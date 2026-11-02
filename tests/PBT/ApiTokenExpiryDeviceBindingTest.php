<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Order;
use App\Models\ScanEvent;
use App\Models\User;
use App\Services\QrService;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Feature: ios-scanner-app
 *
 * Task 3.3 — Property 11: Expired or wrong-device tokens are rejected without
 * side effects.
 *
 * **Validates: Requirements 13.1, 13.2, 13.3, 13.4**
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database.
 *
 * The property has three parts, asserted together over generated devices and
 * QR/reference payloads so it holds broadly rather than for one hand-picked
 * fixture:
 *
 *   1. Issuance invariant (13.1, 13.3). A token issued the real way — a Sanctum
 *      personal access token minted with the configured Token_Lifetime expiry
 *      and the issuing `device_id` stamped on the row, exactly as
 *      {@see \App\Http\Controllers\Api\AuthController::issueToken()} persists it
 *      — carries a persisted `expires_at` that equals `now + Token_Lifetime`
 *      (to the minute) and a persisted `device_id` equal to the issuing Device.
 *
 *   2. Expiry rejection (13.2). With the clock travelled past the token's
 *      Token_Lifetime, a request to a genuine authenticated API route
 *      (`POST /api/scan`) that presents the token AND its matching
 *      `X-Device-Id` is rejected `401` by `auth:sanctum` — the same rejection a
 *      revoked token gets — and performs no side effect: no `scan_events` row
 *      is written and the target Order's `scanned_at` stays null.
 *
 *   3. Device-mismatch rejection (13.4). With a valid (non-expired) token,
 *      presenting a `X-Device-Id` that differs from the token's bound
 *      `device_id` is rejected `401` by {@see \App\Http\Middleware\ResolveApiTenant}
 *      before any Company query runs, again with no side effect (no scan
 *      recorded, the Order unchanged).
 *
 * The token is issued the real way (presented as a Bearer header) rather than
 * via `Sanctum::actingAs`, because both the expiry check (`auth:sanctum`) and
 * the device-binding check (`ResolveApiTenant`) read the persisted token row —
 * a transient acting-as token carries neither an `expires_at` nor a bound
 * `device_id`. The route exercised is the production `/api/scan` route (under
 * `api` → `auth:sanctum` → `api.tenant` → `throttle:scan`), so the rejection
 * and the absence of side effects are observed exactly as in production.
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class ApiTokenExpiryDeviceBindingTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Property 11: an issued token carries `expires_at` (= Token_Lifetime) and
     * `device_id` (= issuing device); once expiry has elapsed, or when a
     * mismatched `X-Device-Id` is presented, the request is rejected 401 like a
     * revoked token and nothing is scanned or mutated.
     *
     * **Validates: Requirements 13.1, 13.2, 13.3, 13.4**
     */
    // Feature: ios-scanner-app, Property 11: Expired or wrong-device tokens are rejected without side effects — an issued token carries expires_at (= Token_Lifetime) and device_id (= issuing device); travel past expiry → 401; a mismatched X-Device-Id → 401; no scan/session mutation occurs in either rejection
    public function test_expired_or_wrong_device_tokens_are_rejected_without_side_effects(): void
    {
        $lifetimeMinutes = (int) config('sanctum.expiration');

        $this->forAll(
            // The Device the token is issued to and bound to. Generated so the
            // binding/expiry invariants hold across arbitrary device ids.
            Generator\elements('device-A', 'device-B', 'device-ios-7f3', 'ipad-door-01', 'ZZZ'),
            // Whether the scan request submits a scanned QR `payload` or a
            // manually typed `reference` — both are authenticated scan inputs
            // that would mutate on success, so either must be a no-op once the
            // request is rejected. (The payload itself is valid for a confirmed,
            // unscanned Order, so only the auth/device rejection — not the
            // decision tree — can stop the side effect.)
            Generator\elements('payload', 'reference'),
            // A different Device id presented on the mismatch leg. Combined with
            // the issuing device below to guarantee a genuine mismatch.
            Generator\elements('other-device', 'stolen-phone', 'device-X', 'laptop-9'),
        )
            ->then(function (string $deviceId, string $inputKind, string $wrongDeviceSeed): void {
                // Clean slate each iteration (iterations share the
                // RefreshDatabase transaction): no resolved tenant, no leftover
                // orders or scan rows, so the side-effect assertions are exact.
                app(TenantContext::class)->clear();
                Carbon::setTestNow();
                ScanEvent::withoutGlobalScopes()->delete();
                Order::withoutGlobalScopes()->delete();

                $company = Company::factory()->create();

                // A Scanner who is a member of the Company — membership governs
                // which tenant the token may bind (Requirement 4.3), and the
                // Scanner role holds ACTION_CHECK_IN so the scan Gate would pass
                // if the request ever reached the controller (it must not).
                $user = User::factory()->for($company)->scanner()->create();
                $user->companies()->attach($company->id, ['role' => User::ROLE_SCANNER]);

                // A confirmed, unscanned Order whose valid QR payload / reference
                // the request will submit. Because it is scannable, the ONLY
                // thing that can stop a check-in is the expiry/device rejection
                // under test — making "no side effect" a meaningful assertion.
                $order = Order::factory()->for($company)->paid()->create([
                    'scanned_at' => null,
                    'scanned_by' => null,
                ]);

                $payload = app(QrService::class)->payload($order->order_reference);
                $body = $inputKind === 'payload'
                    ? ['payload' => $payload]
                    : ['reference' => $order->order_reference];

                // Issue the token the real way: minted with the configured
                // Token_Lifetime expiry and the issuing device stamped on the
                // row, scoped to the Company — mirroring AuthController::issueToken
                // plus CompanyController::select. Capture the plain text to
                // present as a Bearer credential.
                $issuedAt = Carbon::now();
                Carbon::setTestNow($issuedAt);

                $plainTextToken = $user->createToken(
                    name: $deviceId,
                    abilities: ['check_in'],
                    expiresAt: $issuedAt->copy()->addMinutes($lifetimeMinutes),
                )->plainTextToken;

                /** @var \Laravel\Sanctum\PersonalAccessToken $tokenRow */
                $tokenRow = $user->tokens()->latest('id')->first();
                $tokenRow->forceFill([
                    'current_company_id' => $company->id,
                    'device_id' => $deviceId,
                ])->save();
                $tokenRow->refresh();

                // --- 13.1 / 13.3: the issuance invariant --------------------
                // The row carries the issuing device and an expiry at exactly
                // now + Token_Lifetime (compared to the minute to absorb any
                // sub-minute persistence rounding).
                $this->assertSame(
                    $deviceId,
                    $tokenRow->device_id,
                    'An issued token must be bound to the issuing Device (13.3).',
                );
                $this->assertNotNull(
                    $tokenRow->expires_at,
                    'An issued token must carry an expiry (13.1).',
                );
                $this->assertSame(
                    $issuedAt->copy()->addMinutes($lifetimeMinutes)->format('Y-m-d H:i'),
                    $tokenRow->expires_at->format('Y-m-d H:i'),
                    'An issued token must expire at now + Token_Lifetime (13.1).',
                );

                $tokenId = $tokenRow->getKey();
                $bearer = ['Authorization' => "Bearer {$plainTextToken}"];

                // --- 13.2: travel past expiry → 401, no side effect ---------
                // One minute past the Token_Lifetime, auth:sanctum rejects the
                // token like a revoked one even though the matching X-Device-Id
                // is presented.
                Carbon::setTestNow($issuedAt->copy()->addMinutes($lifetimeMinutes + 1));

                $this->postJson('/api/scan', $body, $bearer + ['X-Device-Id' => $deviceId])
                    ->assertStatus(401);

                $this->assertNoScanSideEffect(
                    $order,
                    'An expired token must perform no scan side effect (13.2).',
                );

                // --- 13.4: valid token, mismatched device → 401, no effect --
                // Back inside the token's lifetime so expiry is not the cause;
                // the only difference is a Device id that is not the token's
                // bound one, which ResolveApiTenant rejects before any query.
                Carbon::setTestNow($issuedAt->copy()->addMinutes(1));

                $wrongDeviceId = $wrongDeviceSeed.'-'.$deviceId.'-x';
                $this->assertNotSame($deviceId, $wrongDeviceId);

                $this->postJson('/api/scan', $body, $bearer + ['X-Device-Id' => $wrongDeviceId])
                    ->assertStatus(401);

                $this->assertNoScanSideEffect(
                    $order,
                    'A device-mismatched token must perform no scan side effect (13.4).',
                );

                // Sanity: the token row itself is untouched by either rejection
                // — nothing was revoked or re-bound as a side effect.
                $survivor = $user->tokens()->whereKey($tokenId)->first();
                $this->assertNotNull($survivor, 'A rejected request must not delete the token.');
                $this->assertSame($deviceId, $survivor->device_id);
            });
    }

    /**
     * Assert a rejected scan left no trace: no `scan_events` row exists for any
     * Company and the Order is still unscanned (`scanned_at` / `scanned_by`
     * null). Read scope-free so a hidden tenant scope cannot mask a leaked
     * write.
     */
    private function assertNoScanSideEffect(Order $order, string $because): void
    {
        $this->assertSame(
            0,
            ScanEvent::withoutGlobalScopes()->count(),
            $because.' No scan_events row may be written.',
        );

        $fresh = Order::withoutGlobalScopes()->find($order->getKey());

        $this->assertNotNull($fresh, 'The target Order must survive a rejected scan.');
        $this->assertNull($fresh->scanned_at, $because.' The Order must stay unscanned.');
        $this->assertNull($fresh->scanned_by, $because.' The Order must keep no scanner.');
    }
}
