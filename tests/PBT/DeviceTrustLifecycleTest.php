<?php

namespace Tests\PBT;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * Feature: ios-scanner-app
 *
 * Task 6.4 — Property 8: Device trust is established only by a passed challenge
 * and then persists.
 *
 * **Validates: Requirements 2.1, 2.4, 1.4**
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database.
 *
 * The property walks the whole trust lifecycle for a 2FA-enabled user, driving
 * the real {@see \App\Http\Controllers\Api\AuthController} via the public
 * `/api/auth/login` and `/api/auth/two-factor` endpoints:
 *
 *   1. A login from an UNTRUSTED device returns `2fa_required` with NO token,
 *      and leaves NO `trusted_devices` row — the password alone trusts nothing
 *      (Requirements 1.3, 2.1).
 *   2. A FAILED challenge (wrong TOTP) at `/api/auth/two-factor` returns 422,
 *      issues no token, and STILL leaves no trust row — trust is granted only
 *      by a PASSED challenge (Requirements 2.1, 2.3).
 *   3. A SUCCESSFUL challenge (a valid TOTP, or a valid recovery code) trusts
 *      the device — exactly one `trusted_devices` row for `(user, device)`
 *      appears (Requirement 2.1) — and issues a token.
 *   4. Trust PERSISTS: a later login from the SAME device for that user issues
 *      a token directly, WITHOUT `2fa_required` — the challenge is skipped
 *      (Requirements 2.4, 1.4).
 *   5. Trust is device-scoped: a login from a DIFFERENT, untrusted device for
 *      the same user still demands the challenge (`2fa_required`, no token).
 *
 * Generated over device ids and over whether the successful challenge uses a
 * TOTP or a recovery code (and over a wrong code for the failure branch), so
 * the property holds broadly rather than for one fixed device/code.
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class DeviceTrustLifecycleTest extends PbtTestCase
{
    use RefreshDatabase;

    /** The password every generated user is created with. */
    private const PASSWORD = 'secret-password';

    /**
     * Property 8: a device becomes trusted only after a passed TOTP/recovery
     * challenge, and then later logins from that device skip the challenge
     * while a different device still faces it.
     *
     * **Validates: Requirements 2.1, 2.4, 1.4**
     */
    // Feature: ios-scanner-app, Property 8: Device trust is established only by a passed challenge and then persists — a device becomes trusted only after a successful TOTP challenge; later logins from that device skip the challenge
    public function test_device_trust_is_established_only_by_a_passed_challenge_and_then_persists(): void
    {
        $this->forAll(
            // The trusted device's opaque id (the X-Device-Id the client sends).
            Generator\elements('ios-A1B2', 'ipad-9Z9Z', 'phone-0000', 'dev-abc-123', 'scanner-xyz'),
            // A second, distinct device id used to prove trust is device-scoped.
            Generator\elements('other-C3D4', 'ipad-7Q7Q', 'phone-1111', 'dev-zzz-999', 'scanner-uvw'),
            // Whether the passing challenge uses a TOTP (true) or a recovery
            // code (false) — both must establish trust identically (Req 2.1).
            Generator\elements(true, false),
            // A wrong 6-digit code for the failure branch, kept clear of any
            // real current OTP so the failed challenge genuinely fails.
            Generator\elements('000000', '111111', '123456', '999999'),
        )
            ->then(function (
                string $trustedDeviceId,
                string $otherDeviceId,
                bool $passViaTotp,
                string $wrongCode,
            ): void {
                // Iterations share the RefreshDatabase transaction, so start
                // each from a clean slate: no users, no trust rows carried over.
                TrustedDevice::query()->delete();
                User::query()->delete();

                // Ensure the two device ids are genuinely distinct even if the
                // generators happen to pick the same element.
                if ($trustedDeviceId === $otherDeviceId) {
                    $otherDeviceId .= '-alt';
                }

                // A 2FA-enabled Scanner. MFA is activated directly through the
                // service, as if enrolment had already completed.
                $user = User::factory()->scanner()->create([
                    'password' => Hash::make(self::PASSWORD),
                ]);
                $this->enableMfa($user);

                $baseCredentials = [
                    'email' => $user->email,
                    'password' => self::PASSWORD,
                ];

                // ---- 1. Login from an untrusted device: 2fa_required, no token,
                //         no trust row. The password alone trusts nothing. ----
                $this->postJson('/api/auth/login', $baseCredentials + [
                    'device_id' => $trustedDeviceId,
                ])
                    ->assertOk()
                    ->assertExactJson(['status' => '2fa_required']);

                $this->assertDeviceNotTrusted($user, $trustedDeviceId);

                // ---- 2. A FAILED challenge must NOT trust the device: 422, no
                //         token, still no trust row (Requirements 2.1, 2.3). ----
                $this->postJson('/api/auth/two-factor', $baseCredentials + [
                    'device_id' => $trustedDeviceId,
                    'code' => $wrongCode,
                ])
                    ->assertStatus(422)
                    ->assertJsonMissingPath('token');

                $this->assertDeviceNotTrusted($user, $trustedDeviceId);

                // ---- 3. A SUCCESSFUL challenge trusts the device and issues a
                //         token (Requirement 2.1). ----
                if ($passViaTotp) {
                    $challenge = ['code' => $this->currentCodeFor($user)];
                } else {
                    $challenge = ['recovery_code' => $user->refresh()->two_factor_recovery_codes[0]];
                }

                $twoFactor = $this->postJson('/api/auth/two-factor', $baseCredentials + [
                    'device_id' => $trustedDeviceId,
                ] + $challenge);

                $twoFactor->assertOk();
                $this->assertNotEmpty(
                    $twoFactor->json('token'),
                    'A passed challenge must issue a token.',
                );

                // Exactly one trust row for this (user, device) now exists, and
                // none for any other device of this user.
                $this->assertSame(
                    1,
                    TrustedDevice::query()
                        ->where('user_id', $user->getKey())
                        ->where('device_id', $trustedDeviceId)
                        ->count(),
                    'A passed challenge must create exactly one trust row for the device (Req 2.1).',
                );
                $this->assertSame(
                    1,
                    TrustedDevice::query()->where('user_id', $user->getKey())->count(),
                    'Only the challenged device becomes trusted — no others (Req 2.1).',
                );

                // ---- 4. Trust PERSISTS: a later login from the SAME device
                //         issues a token directly, no challenge (Req 2.4, 1.4). ----
                $repeat = $this->postJson('/api/auth/login', $baseCredentials + [
                    'device_id' => $trustedDeviceId,
                ]);

                $repeat->assertOk();
                $this->assertNotEmpty(
                    $repeat->json('token'),
                    'A trusted device must receive a token at login without a challenge (Req 2.4, 1.4).',
                );
                $repeat->assertJsonMissingPath('status');

                // ---- 5. Trust is device-scoped: a DIFFERENT, untrusted device
                //         for the same user still faces the challenge. ----
                $this->postJson('/api/auth/login', $baseCredentials + [
                    'device_id' => $otherDeviceId,
                ])
                    ->assertOk()
                    ->assertExactJson(['status' => '2fa_required']);

                $this->assertDeviceNotTrusted($user, $otherDeviceId);
            });
    }

    /**
     * Assert no `trusted_devices` row exists for the `(user, device)` pair — the
     * device is not (yet) trusted.
     */
    private function assertDeviceNotTrusted(User $user, string $deviceId): void
    {
        $this->assertSame(
            0,
            TrustedDevice::query()
                ->where('user_id', $user->getKey())
                ->where('device_id', $deviceId)
                ->count(),
            'The device must not be trusted (no trusted_devices row).',
        );
    }

    /** The current valid TOTP for the user's confirmed secret. */
    private function currentCodeFor(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp($user->refresh()->two_factor_secret);
    }

    /**
     * Activate confirmed MFA for the user directly through the service, as if
     * they had already completed enrolment — mirroring the existing web
     * two-factor tests' helper.
     */
    private function enableMfa(User $user): void
    {
        $service = app(TwoFactorAuthenticationService::class);
        $service->startEnrolment($user);
        $service->confirm($user, $this->currentCodeFor($user));
        $user->refresh();
    }
}
