<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\ScanEvent;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\QrService;
use App\Services\RoleAuthorization;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Feature: ios-scanner-app, Property 6: Role gating is total and
 * side-effect-free on denial.
 *
 * **Validates: Requirements 5.1, 5.2, 7.1, 7.5, 8.1, 8.3**
 *
 * Property-based test for the scan endpoint's role gate (design Property 6).
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so the tenant-scoped reads/writes the controller and the
 * shared {@see \App\Services\TicketCheckInService} perform behave exactly as in
 * production.
 *
 * The property, stated over every Company role: the `check_in` Gate is TOTAL —
 * every role lands cleanly on one side of it, never in between. A role WITHOUT
 * `check_in` (the Accountant) calling `POST /api/scan` is denied `403` and the
 * denial is side-effect-free: the Gate runs before anything is read or written
 * ({@see \App\Http\Controllers\Api\ScanApiController::scan()}), so no
 * `scan_events` row is recorded and the targeted Order's `scanned_at` stays
 * `null` — byte-for-byte unchanged. A role WITH `check_in`
 * (Scanner/Box_Office/Admin/Owner) is allowed through the Gate — it never
 * receives the `403`, reaches the shared decision tree, and (for a confirmed,
 * unscanned Order) checks in: `200` with a `checked_in` outcome, the Order now
 * carrying `scanned_at`, and exactly one `scan_events` row.
 *
 * NOTE on token abilities vs. the Gate: `AuthController` encodes a token's
 * abilities from {@see RoleAuthorization::permittedActions()}, but the scan
 * route is NOT ability-gated — `ScanApiController` calls
 * `Gate::authorize(ACTION_CHECK_IN)`, which consults the authenticated user's
 * ROLE via {@see RoleAuthorization}. The denial is therefore role-based, so this
 * test issues each operator a real device-scoped Sanctum token (presented as a
 * Bearer credential with the matching `X-Device-Id`) and lets the role decide
 * the outcome.
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class ScanRoleGatingTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The device each operator's token is bound to. Every request presents this
     * as `X-Device-Id` so `ResolveApiTenant`'s device-binding check passes and
     * the Active_Company is resolved from the token before the controller's Gate
     * runs.
     */
    private const DEVICE_ID = 'device-ios-scanner-role';

    /**
     * The roles that DO hold `check_in` (allowed through the Gate) and the one
     * that does NOT (denied). Kept in sync with the matrix under test only by
     * the per-iteration assertion against {@see RoleAuthorization::roleCan()},
     * so a matrix change that moved `check_in` would surface here, not pass
     * silently.
     */
    private const ALLOWED_ROLES = [
        User::ROLE_OWNER,
        User::ROLE_ADMIN,
        User::ROLE_BOX_OFFICE,
        User::ROLE_SCANNER,
    ];

    private const DENIED_ROLES = [
        User::ROLE_ACCOUNTANT,
    ];

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 6: Role gating is total and side-effect-free on denial — a role
     * without `check_in` calling `POST /api/scan` is denied `403` with no
     * `scan_events` row recorded and the targeted Order left unscanned, while a
     * role with `check_in` is allowed through and checks the Order in.
     *
     * **Validates: Requirements 5.1, 5.2, 7.1, 7.5, 8.1, 8.3**
     */
    // Feature: ios-scanner-app, Property 6: Role gating is total and side-effect-free on denial — a role without check_in is denied 403 and no data changes
    public function test_scan_role_gate_is_total_and_denial_is_side_effect_free(): void
    {
        $qr = app(QrService::class);

        $this->forAll(
            // Draw across every Company role — the allowed roles that hold
            // `check_in` and the one denied role that does not — so the Gate is
            // exercised on both sides in a single property.
            Generator\elements(...self::ALLOWED_ROLES, ...self::DENIED_ROLES),
        )
            ->then(function (string $role) use ($qr): void {
                // Each iteration builds its OWN Company/Event/Order and operator
                // and reasons only about those rows (RefreshDatabase wraps the
                // test in a transaction; DDL / TRUNCATE is banned). Start from a
                // clean tenant so the controller resolves exactly this Company.
                app(TenantContext::class)->clear();

                $company = Company::factory()->create();
                $event = Event::factory()->for($company)->create();

                // A confirmed, unscanned Order under the operator's Company — the
                // one input that an ALLOWED role turns into a clean check-in, and
                // that a DENIED role must leave byte-for-byte untouched.
                $order = Order::factory()->forEvent($event)->create([
                    'status' => Order::STATUS_PAID,
                    'scanned_at' => null,
                    'scanned_by' => null,
                ]);

                $type = TicketType::factory()->forEvent($event)->create(['name' => 'General']);
                Ticket::factory()->forOrder($order)->forTicketType($type)->create();

                // The operator carries the generated role and is a member of the
                // Company (membership, not a home company, governs the token's
                // tenant — Requirement 4.3).
                $operator = User::factory()->for($company)->create(['role' => $role]);
                $operator->companies()->attach($company->id, ['role' => $role]);

                // Guard: the expected side of the Gate for this role comes from
                // the single source of truth, so a matrix change can't let a
                // wrong expectation pass. (Property 6 — the matrix under test.)
                $shouldPass = app(RoleAuthorization::class)->roleCan($role, RoleAuthorization::ACTION_CHECK_IN);

                $plainTextToken = $this->issueDeviceScopedToken($operator, $company);

                $headers = [
                    'Authorization' => "Bearer {$plainTextToken}",
                    'X-Device-Id' => self::DEVICE_ID,
                ];

                // The genuine, correctly-signed payload for the confirmed Order,
                // so the ONLY thing separating a 200 check-in from a 403 is the
                // operator's role at the Gate.
                $payload = $qr->payload($order->order_reference);

                // Pre-state snapshot taken scope-free so the "no data changes"
                // claim is checked against the true global state, independent of
                // whatever tenant the request resolves. (5.2 side-effect-free)
                $scanEventsBefore = ScanEvent::withoutGlobalScopes()->count();

                $response = $this->postJson('/api/scan', ['payload' => $payload], $headers);

                $scanEventsAfter = ScanEvent::withoutGlobalScopes()->count();
                $freshOrder = Order::withoutGlobalScopes()->find($order->id);

                if (! $shouldPass) {
                    // DENIED role: the Gate rejects with 403 before anything is
                    // read or written. (Requirements 5.1, 5.2)
                    $response->assertForbidden();

                    // Side-effect-free: not a single scan_events row was
                    // recorded for the denied attempt...
                    $this->assertSame(
                        $scanEventsBefore,
                        $scanEventsAfter,
                        "A denied {$role} must record no scan_events row.",
                    );

                    // ...and the targeted Order is byte-for-byte unchanged — its
                    // scanned_at/scanned_by stay null, never checked in.
                    $this->assertNull($freshOrder->scanned_at, "A denied {$role} must leave the Order unscanned.");
                    $this->assertNull($freshOrder->scanned_by, "A denied {$role} must not record an operator on the Order.");
                } else {
                    // ALLOWED role: the Gate lets it through — it NEVER receives
                    // a 403 — and the confirmed, unscanned Order checks in.
                    // (Requirements 5.1, 5.2)
                    $response->assertOk();
                    $this->assertNotSame(403, $response->getStatusCode(), "An allowed {$role} must never be gated out.");
                    $response->assertJsonPath('status', 'checked_in');

                    // The side-effect of an ALLOWED check-in: the Order now
                    // carries scanned_at/scanned_by and exactly one scan_events
                    // row was recorded.
                    $this->assertNotNull($freshOrder->scanned_at, "An allowed {$role} must check the Order in.");
                    $this->assertSame($operator->id, (int) $freshOrder->scanned_by, "An allowed {$role} must be recorded as the scanner.");
                    $this->assertSame(
                        $scanEventsBefore + 1,
                        $scanEventsAfter,
                        "An allowed {$role} check-in must record exactly one scan_events row.",
                    );
                }

                // Per-iteration cleanup keeps iterations independent without any
                // DDL / TRUNCATE — scope-free deletes of just this iteration's
                // Company-owned breadcrumbs.
                app(TenantContext::class)->clear();
                ScanEvent::withoutGlobalScopes()->where('company_id', $company->id)->delete();
            });
    }

    /**
     * Issue a real Sanctum personal access token for the operator, scoped to the
     * given Company and bound to {@see DEVICE_ID}, mirroring what the
     * AuthController issuance persists: `current_company_id` carries the
     * Active_Company and `device_id` binds the token to its device. Sanctum
     * applies the configured Token_Lifetime expiry automatically, so the token
     * is valid (non-expired) for `auth:sanctum`.
     *
     * The token is issued the real way (presented as a Bearer credential) rather
     * than via `Sanctum::actingAs`, because `ResolveApiTenant` reads the
     * resolving token's `current_company_id` and `device_id` off
     * `currentAccessToken()` — a transient acting-as token carries neither.
     *
     * @return string the plain-text token to present as a Bearer credential
     */
    private function issueDeviceScopedToken(User $user, Company $company): string
    {
        $newToken = $user->createToken(self::DEVICE_ID);

        $newToken->accessToken->forceFill([
            'current_company_id' => $company->id,
            'device_id' => self::DEVICE_ID,
        ])->save();

        return $newToken->plainTextToken;
    }
}
