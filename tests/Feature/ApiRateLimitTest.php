<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature: ios-scanner-app — task 13.1
 *
 * The anti-guessing / anti-abuse rate limiters on the three sensitive API
 * endpoints, exercised through the REAL routes and the REAL named limiters:
 *
 *   - `throttle:2fa`   on `POST /api/auth/two-factor` — keyed per email+IP, so
 *     once a source exceeds the per-minute ceiling it is told to back off with
 *     a 429 until the window resets. (Requirement 15.1)
 *   - `throttle:scan`  on `POST /api/scan` — keyed per authenticated operator,
 *     so one operator hammering the door is capped (429) WITHOUT ever
 *     throttling a second operator working the same door. (Requirement 15.2)
 *   - `throttle:login` on `POST /api/auth/login` — still enforced: exceeding the
 *     ceiling returns a 429. (Requirement 15.3)
 *
 * ── The testing-environment wrinkle ──────────────────────────────────────────
 * {@see \App\Providers\AppServiceProvider::registerRateLimiters()} deliberately
 * short-circuits every named limiter to {@see Limit::none()} under the `testing`
 * environment so high-volume suites (e.g. the property-based checkout loops and
 * {@see \Tests\PBT\TokenIssuedIffAuthenticationSucceedsTest}) are not tripped by
 * production ceilings. That guard is captured ONCE when `boot()` runs at app
 * bootstrap, long before any test body executes — so a limit override here is
 * not enough on its own; the limiters must be RE-REGISTERED with live ceilings.
 *
 * This test re-registers `login`, `2fa` and `scan` in its own {@see setUp()}
 * with the SAME keys as production (email+IP for login/2fa, the authenticated
 * operator's id for scan) but reading small, test-local ceilings from
 * `config('api.throttle.*')` — the least invasive way to make the real limiter
 * wiring active without touching the app environment. The route middleware
 * (`throttle:2fa` etc.) resolves these re-registered limiters by name at
 * request time, so the production route stack is exercised unchanged; only the
 * `Limit::none()` ceiling is lifted to a finite one.
 *
 * NOTE: Authored but NOT executed — MySQL is unreachable in this environment so
 * the PHPUnit suite cannot run here. Verified with `php -l` and by review.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** The one correct password every generated account is created with. */
    private const PASSWORD = 'correct-horse-battery';

    /** Small, test-local ceilings so the limit is reached in a few requests. */
    private const LOGIN_LIMIT = 3;

    private const TWO_FACTOR_LIMIT = 3;

    private const SCAN_LIMIT = 3;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin small, deterministic ceilings for this test. The re-registered
        // limiters below read these the same way the production closures read
        // the env-backed defaults.
        config([
            'api.throttle.login' => self::LOGIN_LIMIT,
            'api.throttle.2fa' => self::TWO_FACTOR_LIMIT,
            'api.throttle.scan' => self::SCAN_LIMIT,
        ]);

        // Re-register the three named limiters with ACTIVE finite ceilings,
        // overriding the `Limit::none()` versions the testing-env guard
        // installed at boot. The keys mirror production exactly:
        //   login / 2fa → email + client IP (per-source anti-guessing)
        //   scan        → authenticated operator id (per-operator door cap)
        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute((int) config('api.throttle.login'))
                ->by($email.'|'.$request->ip());
        });

        RateLimiter::for('2fa', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute((int) config('api.throttle.2fa'))
                ->by($email.'|'.$request->ip());
        });

        RateLimiter::for('scan', function (Request $request): Limit {
            return Limit::perMinute((int) config('api.throttle.scan'))
                ->by((string) $request->user()?->getKey());
        });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * 15.1 — The TOTP submission endpoint caps a given source: once the
     * per-minute ceiling is exceeded, further requests get a 429 until the
     * window resets; after the window passes the source recovers.
     *
     * The payload is deliberately an invalid/absent second factor so the
     * controller would normally answer 422 — the point is that BEFORE the
     * ceiling is reached the throttle is not what answers (status is not 429),
     * and AFTER it is exceeded the throttle middleware short-circuits with a
     * 429 regardless of the controller outcome.
     *
     * **Requirement 15.1**
     */
    public function test_two_factor_endpoint_throttles_then_recovers_after_the_window(): void
    {
        $user = User::factory()->scanner()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $payload = [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device_id' => 'device-ios-scanner-2fa',
            'code' => '000000', // a wrong code: the controller path is 422, not 429
        ];

        // The first TWO_FACTOR_LIMIT requests are allowed through to the
        // controller (which rejects the bad code with 422) — never a 429.
        for ($i = 0; $i < self::TWO_FACTOR_LIMIT; $i++) {
            $this->postJson('/api/auth/two-factor', $payload)
                ->assertStatus(422);
        }

        // The very next request from the same source (same email+IP) exceeds
        // the ceiling and is rejected by the limiter with 429.
        $this->postJson('/api/auth/two-factor', $payload)
            ->assertStatus(429);

        // Travel past the one-minute window; the counter resets and the source
        // recovers — the next request reaches the controller again (422, not
        // 429).
        $this->travel(61)->seconds();

        $this->postJson('/api/auth/two-factor', $payload)
            ->assertStatus(422);
    }

    /**
     * 15.2 — The scan endpoint is keyed per authenticated operator: operator A
     * exceeding the ceiling gets a 429, while operator B — a different
     * authenticated operator hitting the same endpoint at the same time — is
     * unaffected and still reaches the controller (200). This is exactly the
     * guarantee the per-operator key buys: one operator's abuse never throttles
     * another operator's door.
     *
     * **Requirement 15.2**
     */
    public function test_scan_endpoint_throttles_per_operator_without_affecting_another_operator(): void
    {
        $company = Company::factory()->create();

        [$operatorA, $headersA] = $this->deviceScopedScanner($company, 'device-operator-a');
        [$operatorB, $headersB] = $this->deviceScopedScanner($company, 'device-operator-b');

        // Blank input resolves to the `unreadable` Scan_Outcome — a 200 the
        // controller always answers for a resolved request. That makes a 429
        // unambiguously the limiter's doing, never a controller error.
        $scanBody = [];

        // Operator A burns the ceiling; every request within it is a normal 200.
        for ($i = 0; $i < self::SCAN_LIMIT; $i++) {
            $this->postJson('/api/scan', $scanBody, $headersA)
                ->assertStatus(200);
        }

        // A's next request exceeds the per-operator ceiling → 429 for A.
        $this->postJson('/api/scan', $scanBody, $headersA)
            ->assertStatus(429);

        // Operator B — a DIFFERENT authenticated operator — is on their own
        // per-operator counter, so they are untouched by A's throttling and
        // still reach the controller (200). This is the per-operator key.
        $this->postJson('/api/scan', $scanBody, $headersB)
            ->assertStatus(200);
    }

    /**
     * 15.3 — The sign-in endpoint continues to be rate-limited: once the
     * per-source ceiling is exceeded, further sign-in attempts get a 429.
     *
     * Wrong credentials are used so the controller path is 422; the assertion
     * is that the throttle still bites on top of that (429 once exceeded),
     * proving the login limiter remains in force.
     *
     * **Requirement 15.3**
     */
    public function test_login_endpoint_is_still_rate_limited(): void
    {
        $user = User::factory()->scanner()->create([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $payload = [
            'email' => $user->email,
            'password' => 'wrong-password', // controller path is 422
            'device_id' => 'device-ios-scanner-login',
        ];

        // Up to the ceiling, requests reach the controller (422 for bad creds).
        for ($i = 0; $i < self::LOGIN_LIMIT; $i++) {
            $this->postJson('/api/auth/login', $payload)
                ->assertStatus(422);
        }

        // Exceeding the ceiling → 429, proving login is still throttled.
        $this->postJson('/api/auth/login', $payload)
            ->assertStatus(429);
    }

    /**
     * Create a check-in-permitted Scanner who is a member of the given Company
     * and issue a real Sanctum token scoped to that Company and bound to the
     * given device, returning the operator and the headers to present it with
     * — mirroring {@see ScanApiOutcomeContractTest::authHeaders()} and what
     * {@see \App\Http\Middleware\ResolveApiTenant} reads to bind the tenant.
     *
     * @return array{0: User, 1: array<string, string>}
     */
    private function deviceScopedScanner(Company $company, string $deviceId): array
    {
        $operator = User::factory()->for($company)->scanner()->create();
        $operator->companies()->attach($company->id, ['role' => User::ROLE_SCANNER]);

        $newToken = $operator->createToken($deviceId);

        $newToken->accessToken->forceFill([
            'current_company_id' => $company->id,
            'device_id' => $deviceId,
        ])->save();

        return [
            $operator,
            [
                'Authorization' => 'Bearer '.$newToken->plainTextToken,
                'X-Device-Id' => $deviceId,
            ],
        ];
    }
}
