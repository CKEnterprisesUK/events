<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * Feature: ios-scanner-app
 *
 * Task 3.2 — Property 5: Tenant scope confines every API response.
 *
 * **Validates: Requirements 4.1, 12.3, 7.4**
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database.
 *
 * The scan/dashboard/customer endpoints are not built yet, so this exercises
 * tenant isolation at the MIDDLEWARE layer — the layer that actually enforces
 * it. A test-only route is registered under the production `api` group,
 * `auth:sanctum`, and the `api.tenant` group (so `EnforceTenantScope` +
 * {@see \App\Http\Middleware\ResolveApiTenant} run exactly as in production).
 * It reads and mutates a genuinely Company-owned model ({@see Order}, which
 * carries the {@see \App\Models\Concerns\BelongsToCompany} global scope), so
 * what the route can see is governed solely by the Active_Company the token
 * resolves.
 *
 * The token is issued the real way (a Sanctum personal access token presented
 * as a Bearer header) rather than via `Sanctum::actingAs`, because
 * `ResolveApiTenant` reads the resolving token's `current_company_id` and
 * `device_id` off `currentAccessToken()` — a transient acting-as token carries
 * neither. The token is scoped to Company A (`current_company_id = A`) and
 * bound to a device; every request carries the matching `X-Device-Id`.
 *
 * The property: with an A-scoped token, every response is confined to Company
 * A. The token can never read or mutate Company B's tenant-scoped records —
 * listing returns only A's rows and never B's, and targeting a B row by id
 * yields not-found with the row left byte-for-byte unchanged. This is the API
 * expression of Requirement 4.1 (token-scoped tenant), 12.3 (API responses
 * confined to the tenant), and 7.4 (a dashboard/report read never leaks
 * another Company's data).
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class ApiTenantIsolationTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The device the A-scoped token is bound to. Every request presents this
     * value as `X-Device-Id` so `ResolveApiTenant`'s device-binding check
     * passes and the tenant is resolved.
     */
    private const DEVICE_ID = 'device-ios-scanner-A';

    protected function setUp(): void
    {
        parent::setUp();

        // Test-only tenant-scoped API surface, registered under the SAME groups
        // as the real iOS endpoints: `api` (prepends EnforceHttps, lifted in
        // the testing env), `auth:sanctum` (rejects missing/expired/revoked
        // tokens and populates currentAccessToken), and `api.tenant`
        // (EnforceTenantScope + ResolveApiTenant). The handlers query/mutate the
        // Company-owned Order model through its global scope.
        Route::middleware(['api', 'auth:sanctum', 'api.tenant'])->group(function () {
            // List: returns the ids of every Order visible under the resolved
            // tenant.
            Route::get('/api/_tenant-probe/orders', function () {
                return response()->json([
                    'ids' => Order::query()->orderBy('id')->pluck('id'),
                ]);
            });

            // Show: a single Order by id. A row outside the resolved tenant is
            // invisible to the scope, so firstOrFail 404s.
            Route::get('/api/_tenant-probe/orders/{id}', function (int $id) {
                $order = Order::query()->whereKey($id)->firstOrFail();

                return response()->json(['id' => $order->id]);
            });

            // Mutate: rename the customer on a single Order by id. A scoped
            // update targeting a foreign row matches nothing (0 affected), so it
            // 404s and the foreign row is never touched.
            Route::patch('/api/_tenant-probe/orders/{id}', function (int $id) {
                $affected = Order::query()
                    ->whereKey($id)
                    ->update(['customer_name' => 'HIJACKED']);

                abort_if($affected === 0, 404);

                return response()->json(['affected' => $affected]);
            });
        });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 5: Tenant scope confines every API response — an A-scoped token
     * reads only Company A's rows and can neither read nor mutate Company B's,
     * which stay byte-for-byte unchanged.
     *
     * **Validates: Requirements 4.1, 12.3, 7.4**
     */
    // Feature: ios-scanner-app, Property 5: Tenant scope confines every API response — a token scoped to company A cannot read or mutate company B's tenant-scoped records
    public function test_token_scoped_to_company_a_cannot_read_or_mutate_company_b_records(): void
    {
        $this->forAll(
            // How many Orders Company A owns (at least one so the A-scoped read
            // has something to return).
            Generator\choose(1, 4),
            // How many Orders Company B owns (at least one so there is always a
            // foreign row to probe).
            Generator\choose(1, 4),
            // Which of B's rows the single-row read/mutate probes target.
            Generator\nat(),
        )
            ->then(function (int $aCount, int $bCount, int $probePick): void {
                // Start each iteration from a clean slate (iterations share the
                // RefreshDatabase transaction). Clear any resolved tenant and
                // every Company-owned fixture, scope-free, so the pre-state is
                // known exactly.
                app(TenantContext::class)->clear();
                Order::withoutGlobalScopes()->delete();

                $companyA = Company::factory()->create();
                $companyB = Company::factory()->create();

                // Seed each Company's Orders directly (scope-free) so ownership
                // is explicit and B's pre-state can be snapshotted byte-for-byte.
                $aIds = [];
                for ($i = 0; $i < $aCount; $i++) {
                    $aIds[] = Order::factory()->create(['company_id' => $companyA->id])->id;
                }

                $bIds = [];
                $bSnapshot = []; // id => full attribute array
                for ($i = 0; $i < $bCount; $i++) {
                    $order = Order::factory()->create(['company_id' => $companyB->id]);
                    $bIds[] = $order->id;
                    $bSnapshot[$order->id] = $order->fresh()->getAttributes();
                }

                // The operator is a member of Company A ONLY — membership, not a
                // single home company, governs which tenant a token may bind
                // (Requirement 4.3). The token is scoped to A and bound to a
                // device.
                $user = User::factory()->for($companyA)->scanner()->create();
                $user->companies()->attach($companyA->id, ['role' => User::ROLE_SCANNER]);

                $plainTextToken = $this->issueDeviceScopedToken($user, $companyA);

                $headers = [
                    'Authorization' => "Bearer {$plainTextToken}",
                    'X-Device-Id' => self::DEVICE_ID,
                ];

                // READ (list): the A-scoped token sees exactly Company A's rows
                // — never any of B's. (Requirements 4.1, 12.3, 7.4)
                $expectedVisible = collect($aIds)->sort()->values()->all();

                $this->getJson('/api/_tenant-probe/orders', $headers)
                    ->assertOk()
                    ->assertJsonPath('ids', $expectedVisible);

                // An A-owned row is readable through the scoped route.
                $this->getJson("/api/_tenant-probe/orders/{$aIds[0]}", $headers)
                    ->assertOk()
                    ->assertJsonPath('id', $aIds[0]);

                // READ (single foreign row): a B row is invisible to the A
                // scope — the scoped lookup 404s rather than leaking it.
                $probeBId = $bIds[$probePick % count($bIds)];

                $this->getJson("/api/_tenant-probe/orders/{$probeBId}", $headers)
                    ->assertNotFound();

                // MUTATE (foreign row): a scoped update targeting a B row
                // matches nothing, so the route 404s and nothing changes.
                $this->patchJson("/api/_tenant-probe/orders/{$probeBId}", [], $headers)
                    ->assertNotFound();

                // Every one of B's rows is byte-for-byte unchanged after the
                // read and mutate attempts — no leak, no write. (12.3)
                foreach ($bIds as $bId) {
                    $current = Order::withoutGlobalScopes()->find($bId);

                    $this->assertNotNull($current, 'A foreign Order must survive scoped API operations.');
                    $this->assertSame(
                        $bSnapshot[$bId],
                        $current->getAttributes(),
                        'A foreign Order must be byte-for-byte unchanged after scoped API access.',
                    );
                }
            });
    }

    /**
     * Issue a real Sanctum personal access token for the user, scoped to the
     * given Company and bound to {@see DEVICE_ID}, mirroring what the (not-yet-
     * built) AuthController issuance will persist: `current_company_id` carries
     * the Active_Company and `device_id` binds the token to its device. Sanctum
     * applies the configured Token_Lifetime expiry automatically, so the token
     * is valid (non-expired) for `auth:sanctum`.
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
