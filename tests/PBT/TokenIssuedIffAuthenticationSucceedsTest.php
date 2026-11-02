<?php

namespace Tests\PBT;

use App\Models\User;
use App\Services\DeviceTrustService;
use App\Services\TwoFactorAuthenticationService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * Feature: ios-scanner-app
 *
 * Task 6.3 — Property 7: A token is issued only when authentication fully
 * succeeds.
 *
 * **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 2.2, 2.3**
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database with {@see RefreshDatabase}.
 *
 * The property is driven through the REAL endpoints — `POST /api/auth/login`
 * and, where login answers `2fa_required`, `POST /api/auth/two-factor` — so it
 * exercises the actual {@see \App\Http\Controllers\Api\AuthController} wiring
 * (`Auth::validate`, `User::hasTwoFactorEnabled`,
 * {@see DeviceTrustService::isTrusted}, {@see TwoFactorAuthenticationService::verifyTotp}
 * / `useRecoveryCode`, the `2fa_required` response, and token issuance),
 * not a re-implementation of it.
 *
 * It generates the full authentication scenario space:
 *   - credentials valid / invalid (right vs wrong password),
 *   - two-factor enabled / disabled on the account,
 *   - the submitting Device already trusted / untrusted,
 *   - and, on the 2FA path, a correct / incorrect second factor supplied,
 *     drawn from a valid TOTP, a valid recovery code, a bad TOTP, a bad
 *     recovery code, or nothing at all.
 *
 * The invariant (the exact "iff" the property name states):
 *
 *   a token is returned  ⇔  credentials are valid
 *                            AND ( 2FA disabled
 *                                  OR the Device is already trusted
 *                                  OR a valid TOTP / recovery code was supplied
 *                                     at the two-factor step )
 *
 * In every other case NO token is returned: bad credentials → 422 at login
 * (Requirement 1.2); a 2FA account on an untrusted Device → `2fa_required`
 * with no token at login (Requirement 1.3), and then 422 with no token at the
 * two-factor step when the second factor is missing/wrong (Requirement 2.3).
 * The positive branches cover 2FA-off issuance and trusted-Device issuance at
 * login (Requirements 1.1, 1.4) and issuance after a passed challenge
 * (Requirement 2.2).
 *
 * The per-email+IP `throttle:login` / `throttle:2fa` limiters (5/min) are
 * disabled here so the invariant can be exercised across 100+ iterations
 * without a 429 masking the token decision — the limiters themselves are a
 * separate concern covered by the rate-limit feature tests (task 13.1). The
 * token-issuance logic under test runs identically with or without them.
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class TokenIssuedIffAuthenticationSucceedsTest extends PbtTestCase
{
    use RefreshDatabase;

    /** The one correct password every generated account is created with. */
    private const PASSWORD = 'correct-horse-battery';

    /** The Device every request submits as its `device_id`. */
    private const DEVICE_ID = 'device-ios-scanner-7';

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the token-issuance invariant from the anti-guessing rate
        // limiters: at 5/min per email+IP they would 429 long before 100
        // iterations complete and that 429 would hide the token decision. The
        // limiters are verified independently (task 13.1); the controller's
        // issuance logic is byte-for-byte identical with them lifted.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * The current valid TOTP for a user's confirmed secret.
     */
    private function currentCodeFor(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp($user->refresh()->two_factor_secret);
    }

    /**
     * Activate confirmed MFA for a user directly through the service, exactly
     * as the web {@see \Tests\Feature\TwoFactorAuthenticationTest} does: start
     * enrolment to mint a secret + recovery codes, then confirm with a live
     * TOTP so `two_factor_confirmed_at` is stamped and `hasTwoFactorEnabled()`
     * is true.
     */
    private function enableMfa(User $user): void
    {
        $service = app(TwoFactorAuthenticationService::class);
        $service->startEnrolment($user);
        $service->confirm($user, $this->currentCodeFor($user));
        $user->refresh();
    }

    /**
     * Property 7: A token is issued only when authentication fully succeeds.
     *
     * A token is returned IFF the credentials are valid AND (2FA is disabled OR
     * the Device is already trusted OR a valid TOTP/recovery code is supplied
     * at the two-factor step); in every other case no token is returned.
     *
     * **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 2.2, 2.3**
     */
    // Feature: ios-scanner-app, Property 7: A token is issued only when authentication fully succeeds — token issued iff credentials valid AND (2FA disabled OR device trusted OR valid TOTP/recovery supplied); no token otherwise
    public function test_token_issued_iff_credentials_valid_and_second_factor_satisfied(): void
    {
        $this->forAll(
            // Credentials valid? (the right vs a wrong password)
            Generator\bool(),
            // Two-factor enabled on the account?
            Generator\bool(),
            // Is the submitting Device already trusted for this user?
            Generator\bool(),
            // Which second factor the client supplies at the two-factor step.
            Generator\elements(
                'valid_totp',      // a live, correct TOTP
                'valid_recovery',  // a real, unused recovery code
                'bad_totp',        // a wrong 6-digit code
                'bad_recovery',    // a recovery code that was never issued
                'none',            // neither code supplied
            ),
        )
            ->then(function (
                bool $credentialsValid,
                bool $twoFactorEnabled,
                bool $deviceTrusted,
                string $secondFactor,
            ): void {
                // Each iteration starts from a clean account so the pre-state is
                // exact (iterations share the RefreshDatabase transaction).
                User::query()->delete();

                $user = User::factory()->scanner()->create([
                    'password' => Hash::make(self::PASSWORD),
                ]);

                if ($twoFactorEnabled) {
                    $this->enableMfa($user);
                }

                if ($deviceTrusted) {
                    app(DeviceTrustService::class)->trust($user, self::DEVICE_ID, null);
                }

                $password = $credentialsValid ? self::PASSWORD : 'wrong-password';

                // ----- Oracle: compute the expected outcome independently -----
                // Login issues a token straight away only when credentials are
                // valid AND (2FA off OR the Device is already trusted).
                $loginIssues = $credentialsValid && (! $twoFactorEnabled || $deviceTrusted);

                // A valid second factor is only meaningful when the flow reaches
                // the two-factor step: credentials valid, 2FA on, Device not yet
                // trusted.
                $secondFactorValid = in_array($secondFactor, ['valid_totp', 'valid_recovery'], true);
                $reachesTwoFactor = $credentialsValid && $twoFactorEnabled && ! $deviceTrusted;
                $twoFactorIssues = $reachesTwoFactor && $secondFactorValid;

                $tokenExpected = $loginIssues || $twoFactorIssues;

                // ----- Drive the real login endpoint -----
                $login = $this->postJson('/api/auth/login', [
                    'email' => $user->email,
                    'password' => $password,
                    'device_id' => self::DEVICE_ID,
                ]);

                if (! $credentialsValid) {
                    // Bad credentials → 422, never a token. (Requirement 1.2)
                    $login->assertStatus(422);
                    $login->assertJsonMissingPath('token');

                    $this->assertFalse($tokenExpected, 'Oracle must agree: no token on invalid credentials.');

                    return;
                }

                if ($loginIssues) {
                    // 2FA off OR Device already trusted → token at login.
                    // (Requirements 1.1, 1.4)
                    $login->assertOk();
                    $login->assertJsonStructure(['token', 'permitted_actions']);
                    $this->assertNotEmpty($login->json('token'));

                    $this->assertTrue($tokenExpected);

                    return;
                }

                // Otherwise: valid credentials, 2FA on, untrusted Device → the
                // challenge is demanded and NO token is issued at login.
                // (Requirement 1.3)
                $login->assertOk();
                $login->assertExactJson(['status' => '2fa_required']);
                $login->assertJsonMissingPath('token');

                // ----- Drive the real two-factor endpoint -----
                $payload = [
                    'email' => $user->email,
                    'password' => $password,
                    'device_id' => self::DEVICE_ID,
                ];

                switch ($secondFactor) {
                    case 'valid_totp':
                        $payload['code'] = $this->currentCodeFor($user);
                        break;
                    case 'valid_recovery':
                        $payload['recovery_code'] = $user->refresh()->two_factor_recovery_codes[0];
                        break;
                    case 'bad_totp':
                        $payload['code'] = '000000';
                        break;
                    case 'bad_recovery':
                        $payload['recovery_code'] = 'zzzz-9999';
                        break;
                    case 'none':
                        // Supply neither code.
                        break;
                }

                $twoFactor = $this->postJson('/api/auth/two-factor', $payload);

                if ($secondFactorValid) {
                    // A valid TOTP/recovery code → token issued. (Requirement 2.2)
                    $twoFactor->assertOk();
                    $twoFactor->assertJsonStructure(['token', 'permitted_actions']);
                    $this->assertNotEmpty($twoFactor->json('token'));

                    $this->assertTrue($tokenExpected);
                } else {
                    // Missing/wrong second factor → 422, no token. (Requirement 2.3)
                    $twoFactor->assertStatus(422);
                    $twoFactor->assertJsonMissingPath('token');

                    $this->assertFalse($tokenExpected);
                }
            });
    }
}
