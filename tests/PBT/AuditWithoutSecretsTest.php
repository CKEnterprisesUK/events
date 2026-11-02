<?php

namespace Tests\PBT;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\DeviceTrustService;
use App\Services\TenantContext;
use App\Services\TwoFactorAuthenticationService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * Feature: ios-scanner-app
 *
 * Task 11.2 — Property 13: Sensitive access events are audited without
 * recording secrets.
 *
 * **Validates: Requirements 16.1, 16.2, 16.3, 16.4, 16.5**
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database with {@see RefreshDatabase}.
 *
 * Every sensitive access event is driven through its REAL production endpoint,
 * so the Audit_Entry asserted here is the one the controllers
 * ({@see \App\Http\Controllers\Api\AuthController},
 * {@see \App\Http\Controllers\Api\CompanyController},
 * {@see \App\Http\Controllers\Api\SessionController}) actually write via the
 * production {@see \App\Services\AuditLogger}. One event kind is generated per
 * iteration so, across 100+ iterations, every kind is exercised:
 *
 *   - `issuance`      — a 2FA-off login issues a token → exactly one
 *                       `TOKEN_ISSUED` naming the Staff_User + Active_Company
 *                       (Requirement 16.1).
 *   - `issuance_2fa`  — a passed TOTP challenge issues a token → exactly one
 *                       `TOKEN_ISSUED` naming the Staff_User (Requirement 16.1).
 *   - `logout`        — self logout → exactly one `TOKEN_REVOKED` scope
 *                       `logout` (Requirement 16.2).
 *   - `self_revoke`   — `DELETE /api/sessions/{id}` → one `TOKEN_REVOKED`
 *                       scope `self`, actor == target (Requirement 16.2).
 *   - `admin_revoke`  — an Owner (ACTION_MANAGE_USERS) revokes a same-company
 *                       member → one `TOKEN_REVOKED` scope `admin` naming
 *                       actor + target (Requirement 16.2).
 *   - `super_revoke`  — a Super_Admin revokes cross-company → one
 *                       `TOKEN_REVOKED` scope `super_admin` naming actor +
 *                       target (Requirement 16.2).
 *   - `company_switch`— `POST /api/companies/{id}/select` → one
 *                       `COMPANY_SELECTED` naming the chosen Company
 *                       (Requirement 16.3).
 *   - `failed_login`  — a wrong password → one `AUTH_LOGIN_FAILED`
 *                       (Requirement 16.4).
 *   - `failed_2fa`    — a wrong TOTP → one `AUTH_MFA_CHALLENGE_FAILED`
 *                       (Requirement 16.4).
 *
 * For EVERY event the test asserts a strict count delta of exactly one new
 * audit row (the triggering event produced one entry, no more), with the right
 * `action`, `actor_user_id`, `company_id`, and `auditable` subject.
 *
 * The core invariant (Requirement 16.5): for every audit row the event
 * produced, neither its `summary` nor its JSON-encoded `context` contains ANY
 * of the secrets in play for that iteration — the plaintext Access_Token, the
 * password, the submitted TOTP code, the recovery code, or the QR HMAC secret
 * (`config('qr.hmac_secret')`). The password, the device id, the TOTP, and the
 * selected company are all generated so the no-leak property holds broadly
 * rather than for one hand-picked fixture.
 *
 * The per-email+IP `throttle:login` / `throttle:2fa` limiters are lifted so the
 * invariant can be exercised across 100+ iterations without a 429 masking the
 * audit write; the limiters are a separate concern (task 13.1) and the audit
 * logic is identical with or without them.
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class AuditWithoutSecretsTest extends PbtTestCase
{
    use RefreshDatabase;

    /** The device every request submits as its `device_id` / `X-Device-Id`. */
    private const DEVICE_ID = 'device-ios-scanner-audit';

    /**
     * The event kinds the property ranges over. One is generated per iteration
     * so, across 100+ iterations, every sensitive access event is exercised.
     *
     * @var list<string>
     */
    private const EVENT_KINDS = [
        'issuance',
        'issuance_2fa',
        'logout',
        'self_revoke',
        'admin_revoke',
        'super_revoke',
        'company_switch',
        'failed_login',
        'failed_2fa',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the audit invariant from the anti-guessing rate limiters: at
        // 5/min per email+IP they would 429 long before 100 iterations complete
        // and that 429 would hide the audit write. The limiters are verified
        // independently (task 13.1); the controllers' AuditLogger calls run
        // byte-for-byte identically with them lifted.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * The current valid TOTP for a user's confirmed secret.
     */
    private function currentCodeFor(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp($user->refresh()->two_factor_secret);
    }

    /**
     * Activate confirmed MFA for a user directly through the service (mint a
     * secret + recovery codes, confirm with a live TOTP), exactly as the web
     * two-factor flow does.
     */
    private function enableMfa(User $user): void
    {
        $service = app(TwoFactorAuthenticationService::class);
        $service->startEnrolment($user);
        $service->confirm($user, $this->currentCodeFor($user));
        $user->refresh();
    }

    /**
     * Issue a real device-bound Sanctum token for the user, scoped to the given
     * Company (or none), mirroring {@see \App\Http\Controllers\Api\AuthController::issueToken()}.
     *
     * @return array{0: string, 1: PersonalAccessToken} the plain-text token and its row
     */
    private function issueDeviceScopedToken(User $user, ?Company $company, array $abilities): array
    {
        $new = $user->createToken(self::DEVICE_ID, $abilities);

        $new->accessToken->forceFill([
            'current_company_id' => $company?->getKey(),
            'device_id' => self::DEVICE_ID,
        ])->save();

        /** @var PersonalAccessToken $row */
        $row = PersonalAccessToken::findToken($new->plainTextToken);

        return [$new->plainTextToken, $row];
    }

    /**
     * Property 13: Sensitive access events are audited without recording
     * secrets — each event writes exactly one Audit_Entry with the right
     * action/actor/company/target, and no recorded summary/context contains the
     * plaintext token, password, TOTP code, recovery code, or QR HMAC secret.
     *
     * **Validates: Requirements 16.1, 16.2, 16.3, 16.4, 16.5**
     */
    // Feature: ios-scanner-app, Property 13: Sensitive access events are audited without recording secrets — one entry per event (issuance, revocation with actor+target, company switch, failed login/2FA) with the right action/actor/company/target, and no recorded summary/context contains the plaintext token, password, TOTP code, or HMAC secret
    public function test_sensitive_access_events_are_audited_without_recording_secrets(): void
    {
        $this->limitTo(self::MIN_ITERATIONS)
            ->forAll(
                // Which sensitive access event this iteration drives.
                Generator\elements(...self::EVENT_KINDS),
                // A generated password so "no password in the trail" holds for
                // arbitrary secrets, not one fixed string.
                Generator\suchThat(
                    static fn (string $p): bool => strlen(trim($p)) >= 8,
                    Generator\string(),
                ),
            )
            ->then(function (string $eventKind, string $password): void {
                // Clean slate each iteration (iterations share the
                // RefreshDatabase transaction) so the audit count delta is exact.
                app(TenantContext::class)->clear();
                AuditLog::query()->delete();
                PersonalAccessToken::query()->delete();
                User::query()->delete();
                Company::query()->delete();

                $this->runEvent($eventKind, $password);
            });
    }

    /**
     * Drive one sensitive access event through its real endpoint and assert the
     * single Audit_Entry it writes — the right action/actor/company/target and,
     * centrally, that none of the iteration's secrets leaked into it.
     */
    private function runEvent(string $eventKind, string $password): void
    {
        $hmacSecret = (string) config('qr.hmac_secret');

        match ($eventKind) {
            'issuance' => $this->assertIssuanceAudited($password, $hmacSecret),
            'issuance_2fa' => $this->assertIssuanceViaTwoFactorAudited($password, $hmacSecret),
            'logout' => $this->assertLogoutAudited($password, $hmacSecret),
            'self_revoke' => $this->assertSelfRevokeAudited($password, $hmacSecret),
            'admin_revoke' => $this->assertAdminRevokeAudited($password, $hmacSecret),
            'super_revoke' => $this->assertSuperAdminRevokeAudited($password, $hmacSecret),
            'company_switch' => $this->assertCompanySwitchAudited($password, $hmacSecret),
            'failed_login' => $this->assertFailedLoginAudited($password, $hmacSecret),
            'failed_2fa' => $this->assertFailedTwoFactorAudited($password, $hmacSecret),
        };
    }

    // ---- Issuance (16.1) -----------------------------------------------------

    /**
     * A 2FA-off login issues a token → exactly one `TOKEN_ISSUED` naming the
     * Staff_User and the Active_Company, with no secret recorded.
     */
    private function assertIssuanceAudited(string $password, string $hmacSecret): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->scanner()->create([
            'password' => Hash::make($password),
        ]);

        $before = AuditLog::query()->count();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password,
            'device_id' => self::DEVICE_ID,
        ])->assertOk();

        $plainToken = (string) $response->json('token');
        $this->assertNotEmpty($plainToken);

        $row = $this->assertExactlyOneNewEntry($before);

        $this->assertSame(AuditLog::TOKEN_ISSUED, $row->action);
        $this->assertSame($user->getKey(), $row->actor_user_id);
        $this->assertSame((int) $company->getKey(), (int) $row->company_id);
        $this->assertSubjectIs($row, $user);

        $this->assertNoSecretsLeaked($row, [$plainToken, $password, $hmacSecret]);
    }

    /**
     * A passed TOTP challenge issues a token → exactly one `TOKEN_ISSUED`
     * naming the Staff_User, recording neither the token, the password, the
     * TOTP code, a recovery code, nor the HMAC secret.
     */
    private function assertIssuanceViaTwoFactorAudited(string $password, string $hmacSecret): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->scanner()->create([
            'password' => Hash::make($password),
        ]);
        $this->enableMfa($user);

        // Login on an untrusted 2FA device returns 2fa_required with no token
        // and writes NO audit entry — only the eventual issuance is audited.
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password,
            'device_id' => self::DEVICE_ID,
        ])->assertOk()->assertExactJson(['status' => '2fa_required']);

        $code = $this->currentCodeFor($user);
        $recoveryCode = $user->refresh()->two_factor_recovery_codes[0];

        $before = AuditLog::query()->count();

        $response = $this->postJson('/api/auth/two-factor', [
            'email' => $user->email,
            'password' => $password,
            'device_id' => self::DEVICE_ID,
            'code' => $code,
        ])->assertOk();

        $plainToken = (string) $response->json('token');
        $this->assertNotEmpty($plainToken);

        $row = $this->assertExactlyOneNewEntry($before);

        $this->assertSame(AuditLog::TOKEN_ISSUED, $row->action);
        $this->assertSame($user->getKey(), $row->actor_user_id);
        $this->assertSubjectIs($row, $user);

        $this->assertNoSecretsLeaked($row, [$plainToken, $password, $code, $recoveryCode, $hmacSecret]);
    }

    // ---- Revocation (16.2) ---------------------------------------------------

    /**
     * Self logout → exactly one `TOKEN_REVOKED` scope `logout`, recording no
     * secret.
     */
    private function assertLogoutAudited(string $password, string $hmacSecret): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->scanner()->create();
        $user->companies()->attach($company->getKey(), ['role' => User::ROLE_SCANNER]);

        [$plainToken] = $this->issueDeviceScopedToken($user, $company, ['check_in']);
        $headers = $this->bearer($plainToken);

        $before = AuditLog::query()->count();

        $this->postJson('/api/auth/logout', [], $headers)->assertNoContent();

        $row = $this->assertExactlyOneNewEntry($before);

        $this->assertSame(AuditLog::TOKEN_REVOKED, $row->action);
        $this->assertSame($user->getKey(), $row->actor_user_id);
        $this->assertSubjectIs($row, $user);
        $this->assertSame('logout', $row->context['scope'] ?? null);

        $this->assertNoSecretsLeaked($row, [$plainToken, $password, $hmacSecret]);
    }

    /**
     * Self session revoke (`DELETE /api/sessions/{id}`) → one `TOKEN_REVOKED`
     * scope `self`, actor == target, recording no secret.
     */
    private function assertSelfRevokeAudited(string $password, string $hmacSecret): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->scanner()->create();
        $user->companies()->attach($company->getKey(), ['role' => User::ROLE_SCANNER]);

        // The token the request authenticates with, plus a second token that is
        // the one actually revoked (so the caller's own session survives).
        [$plainToken] = $this->issueDeviceScopedToken($user, $company, ['check_in']);
        [, $victimRow] = $this->issueDeviceScopedToken($user, $company, ['check_in']);
        $headers = $this->bearer($plainToken);

        $before = AuditLog::query()->count();

        $this->deleteJson("/api/sessions/{$victimRow->getKey()}", [], $headers)
            ->assertNoContent();

        $row = $this->assertExactlyOneNewEntry($before);

        $this->assertSame(AuditLog::TOKEN_REVOKED, $row->action);
        $this->assertSame($user->getKey(), $row->actor_user_id);
        $this->assertSubjectIs($row, $user);
        $this->assertSame('self', $row->context['scope'] ?? null);
        $this->assertSame($user->getKey(), $row->context['revoked_by'] ?? null);

        $this->assertNoSecretsLeaked($row, [$plainToken, $password, $hmacSecret]);
    }

    /**
     * An Owner (holding ACTION_MANAGE_USERS) revokes a same-company member's
     * session → one `TOKEN_REVOKED` scope `admin` naming the acting actor and
     * the target Staff_User, recording no secret.
     */
    private function assertAdminRevokeAudited(string $password, string $hmacSecret): void
    {
        $company = Company::factory()->create();

        $owner = User::factory()->for($company)->owner()->create();
        $owner->companies()->attach($company->getKey(), ['role' => User::ROLE_OWNER]);

        $member = User::factory()->for($company)->scanner()->create();
        $member->companies()->attach($company->getKey(), ['role' => User::ROLE_SCANNER]);

        // The Owner's token carries the `users` ability (required by the route)
        // and is scoped to the Active_Company so the membership check resolves.
        [$ownerToken] = $this->issueDeviceScopedToken(
            $owner,
            $company,
            app(\App\Services\RoleAuthorization::class)->permittedActions(User::ROLE_OWNER),
        );

        // The target member's session token that gets revoked.
        [, $memberTokenRow] = $this->issueDeviceScopedToken($member, $company, ['check_in']);

        $headers = $this->bearer($ownerToken);

        $before = AuditLog::query()->count();

        $this->deleteJson(
            "/api/users/{$member->getKey()}/sessions/{$memberTokenRow->getKey()}",
            [],
            $headers,
        )->assertNoContent();

        $row = $this->assertExactlyOneNewEntry($before);

        $this->assertSame(AuditLog::TOKEN_REVOKED, $row->action);
        $this->assertSame($owner->getKey(), $row->actor_user_id);
        $this->assertSubjectIs($row, $member);
        $this->assertSame('admin', $row->context['scope'] ?? null);
        $this->assertSame($owner->getKey(), $row->context['revoked_by'] ?? null);

        $this->assertNoSecretsLeaked($row, [$ownerToken, $password, $hmacSecret]);
    }

    /**
     * A Super_Admin revokes a member's session cross-company → one
     * `TOKEN_REVOKED` scope `super_admin` naming the actor and target,
     * recording no secret.
     */
    private function assertSuperAdminRevokeAudited(string $password, string $hmacSecret): void
    {
        $company = Company::factory()->create();

        $superAdmin = User::factory()->superAdmin()->create();

        $member = User::factory()->for($company)->scanner()->create();
        $member->companies()->attach($company->getKey(), ['role' => User::ROLE_SCANNER]);

        // A Super_Admin passes the `users` ability gate and the ACTION_MANAGE_USERS
        // check via the Gate::before bypass; the token is scoped to the target's
        // Company so the audit entry gets its company_id.
        [$superToken] = $this->issueDeviceScopedToken($superAdmin, $company, ['users']);

        [, $memberTokenRow] = $this->issueDeviceScopedToken($member, $company, ['check_in']);

        $headers = $this->bearer($superToken);

        $before = AuditLog::query()->count();

        $this->deleteJson(
            "/api/users/{$member->getKey()}/sessions/{$memberTokenRow->getKey()}",
            [],
            $headers,
        )->assertNoContent();

        $row = $this->assertExactlyOneNewEntry($before);

        $this->assertSame(AuditLog::TOKEN_REVOKED, $row->action);
        $this->assertSame($superAdmin->getKey(), $row->actor_user_id);
        $this->assertSubjectIs($row, $member);
        $this->assertSame('super_admin', $row->context['scope'] ?? null);
        $this->assertSame($superAdmin->getKey(), $row->context['revoked_by'] ?? null);

        $this->assertNoSecretsLeaked($row, [$superToken, $password, $hmacSecret]);
    }

    // ---- Company switch (16.3) ----------------------------------------------

    /**
     * Selecting a membership (`POST /api/companies/{id}/select`) → exactly one
     * `COMPANY_SELECTED` naming the chosen Company, recording no secret.
     */
    private function assertCompanySwitchAudited(string $password, string $hmacSecret): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->scanner()->create();
        $user->companies()->attach($company->getKey(), ['role' => User::ROLE_SCANNER]);

        // A token with NO Active_Company yet, as the AuthController hands a user
        // before they pick — `select` is the act that sets it.
        [$plainToken] = $this->issueDeviceScopedToken($user, null, ['check_in']);
        $headers = $this->bearer($plainToken);

        $before = AuditLog::query()->count();

        $this->postJson("/api/companies/{$company->getKey()}/select", [], $headers)
            ->assertOk();

        $row = $this->assertExactlyOneNewEntry($before);

        $this->assertSame(AuditLog::COMPANY_SELECTED, $row->action);
        $this->assertSame($user->getKey(), $row->actor_user_id);
        $this->assertSame((int) $company->getKey(), (int) $row->company_id);
        $this->assertSubjectIs($row, $company);
        $this->assertSame((int) $company->getKey(), (int) ($row->context['company_id'] ?? null));

        $this->assertNoSecretsLeaked($row, [$plainToken, $password, $hmacSecret]);
    }

    // ---- Failed authentication (16.4) ---------------------------------------

    /**
     * A wrong password for a real account → exactly one `AUTH_LOGIN_FAILED`,
     * recording no secret (never the submitted password).
     */
    private function assertFailedLoginAudited(string $password, string $hmacSecret): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->scanner()->create([
            'password' => Hash::make($password),
        ]);

        $wrongPassword = $password.'-WRONG';

        $before = AuditLog::query()->count();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $wrongPassword,
            'device_id' => self::DEVICE_ID,
        ])->assertStatus(422)->assertJsonMissingPath('token');

        $row = $this->assertExactlyOneNewEntry($before);

        $this->assertSame(AuditLog::AUTH_LOGIN_FAILED, $row->action);
        $this->assertSame($user->getKey(), $row->actor_user_id);
        $this->assertSubjectIs($row, $user);

        // Neither the correct nor the submitted (wrong) password may appear.
        $this->assertNoSecretsLeaked($row, [$password, $wrongPassword, $hmacSecret]);
    }

    /**
     * A wrong TOTP for a 2FA account → exactly one `AUTH_MFA_CHALLENGE_FAILED`,
     * recording no secret (never the submitted code, a recovery code, or the
     * password).
     */
    private function assertFailedTwoFactorAudited(string $password, string $hmacSecret): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->scanner()->create([
            'password' => Hash::make($password),
        ]);
        $this->enableMfa($user);

        $wrongCode = '000000';
        $recoveryCode = $user->refresh()->two_factor_recovery_codes[0];

        $before = AuditLog::query()->count();

        $this->postJson('/api/auth/two-factor', [
            'email' => $user->email,
            'password' => $password,
            'device_id' => self::DEVICE_ID,
            'code' => $wrongCode,
        ])->assertStatus(422)->assertJsonMissingPath('token');

        $row = $this->assertExactlyOneNewEntry($before);

        $this->assertSame(AuditLog::AUTH_MFA_CHALLENGE_FAILED, $row->action);
        $this->assertSame($user->getKey(), $row->actor_user_id);
        $this->assertSubjectIs($row, $user);

        $this->assertNoSecretsLeaked($row, [$password, $wrongCode, $recoveryCode, $hmacSecret]);
    }

    // ---- Shared assertions ---------------------------------------------------

    /**
     * Assert the event produced exactly one new audit row (a strict count
     * delta) and return the latest row.
     */
    private function assertExactlyOneNewEntry(int $before): AuditLog
    {
        $this->assertSame(
            $before + 1,
            AuditLog::query()->count(),
            'The event must write exactly one Audit_Entry.',
        );

        /** @var AuditLog $row */
        $row = AuditLog::query()->latest('id')->first();

        return $row;
    }

    /**
     * Assert the audit row's polymorphic subject is the given model.
     */
    private function assertSubjectIs(AuditLog $row, \Illuminate\Database\Eloquent\Model $subject): void
    {
        $this->assertSame($subject->getMorphClass(), $row->auditable_type);
        $this->assertSame((int) $subject->getKey(), (int) $row->auditable_id);
    }

    /**
     * The core Requirement 16.5 assertion: neither the row's `summary` nor its
     * JSON-encoded `context` contains ANY of the given secrets. Empty/whitespace
     * secrets are skipped (a blank string is a vacuous substring of everything
     * and never a leak).
     *
     * @param  list<string|null>  $secrets
     */
    private function assertNoSecretsLeaked(AuditLog $row, array $secrets): void
    {
        $summary = (string) $row->summary;
        $contextJson = json_encode($row->context ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($contextJson, 'The context must be JSON-encodable.');

        $haystack = $summary."\n".$contextJson;

        foreach ($secrets as $secret) {
            if ($secret === null || trim((string) $secret) === '') {
                continue;
            }

            $this->assertStringNotContainsString(
                $secret,
                $haystack,
                'An Audit_Entry must never record a secret (token/password/TOTP/recovery/HMAC). '
                .'Action: '.$row->action,
            );
        }
    }

    /**
     * The bearer + device headers for an issued token.
     *
     * @return array<string, string>
     */
    private function bearer(string $plainToken): array
    {
        return [
            'Authorization' => "Bearer {$plainToken}",
            'X-Device-Id' => self::DEVICE_ID,
        ];
    }
}
