<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Feature: ios-scanner-app
 *
 * Task 7.2 — Property 9: Company selection respects membership.
 *
 * **Validates: Requirements 4.3, 4.4, 4.5**
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database.
 *
 * The property has two halves, both expressed through the production
 * {@see \App\Http\Controllers\Api\CompanyController::select} endpoint
 * (`POST /api/companies/{id}/select`, `auth:sanctum`) and observed through a
 * tenant-scoped probe route registered under the real `api.tenant` group
 * ({@see \App\Http\Middleware\EnforceTenantScope} +
 * {@see \App\Http\Middleware\ResolveApiTenant}) so re-scoping is confirmed
 * exactly as production enforces it:
 *
 *   - MEMBER selection (Requirements 4.3, 4.4). Selecting a Company the user
 *     IS a member of returns 200, stamps the token's `current_company_id` to
 *     that Company, and a subsequent tenant-scoped request resolves the NEW
 *     Company — the probe sees only the selected Company's rows and none of
 *     the others'. Switching to a SECOND membership re-scopes again: the probe
 *     then sees only the second Company's rows.
 *
 *   - NON-MEMBER selection (Requirement 4.5). Selecting a Company the user is
 *     NOT a member of returns 403 and leaves the token's `current_company_id`
 *     exactly as it was, so the previously-selected Company still governs
 *     scope — the probe continues to resolve that earlier Company and the
 *     rejected Company's rows never become visible.
 *
 * The token is issued the real way (a Sanctum personal access token presented
 * as a Bearer header) rather than via `Sanctum::actingAs`, because `select`
 * mutates — and `ResolveApiTenant` reads — the resolving token's
 * `current_company_id`/`device_id` off `currentAccessToken()`, which a
 * transient acting-as token does not carry. The token starts with NO
 * Active_Company (`current_company_id` null), mirroring what the AuthController
 * issues to a multi-membership user before they pick a Company. Every request
 * carries the matching `X-Device-Id` so the device-binding check passes.
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class ApiCompanySelectionTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The device the token is bound to. Every request presents this value as
     * `X-Device-Id` so `ResolveApiTenant`'s device-binding check passes and the
     * selected tenant is resolved.
     */
    private const DEVICE_ID = 'device-ios-scanner-select';

    protected function setUp(): void
    {
        parent::setUp();

        // Test-only tenant-scoped probe, registered under the SAME groups as the
        // real iOS endpoints: `api` (prepends EnforceHttps, lifted in testing),
        // `auth:sanctum` (populates currentAccessToken), and `api.tenant`
        // (EnforceTenantScope + ResolveApiTenant resolve the token's
        // current_company_id). It lists the ids of every Order visible under the
        // resolved tenant, so what it returns is governed solely by which
        // Company the token has currently selected — the direct observable for
        // re-scoping.
        Route::middleware(['api', 'auth:sanctum', 'api.tenant'])->group(function () {
            Route::get('/api/_tenant-probe/orders', function () {
                return response()->json([
                    'ids' => Order::query()->orderBy('id')->pluck('id'),
                ]);
            });
        });
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 9: Company selection respects membership — a member selection
     * sets the Active_Company and re-scopes subsequent requests to it; a
     * non-member selection is rejected 403 and leaves the Active_Company (and
     * therefore the scope) unchanged.
     *
     * **Validates: Requirements 4.3, 4.4, 4.5**
     */
    // Feature: ios-scanner-app, Property 9: Company selection respects membership — member selection sets the Active_Company and re-scopes; non-member selection is rejected and leaves it unchanged
    public function test_company_selection_respects_membership(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Orders owned by the first membership (company the user picks
            // first). At least one so its scoped read has something to return.
            Generator\choose(1, 4),
            // Orders owned by the second membership (the company the user
            // switches to). At least one so the re-scope is observable.
            Generator\choose(1, 4),
            // Orders owned by the NON-member company (the rejected selection).
            // At least one so there is always a foreign row that must never
            // become visible.
            Generator\choose(1, 4),
        )
            ->then(function (int $firstCount, int $secondCount, int $outsiderCount): void {
                // Start each iteration from a clean slate (iterations share the
                // RefreshDatabase transaction). Clear any resolved tenant and
                // every Company-owned fixture, scope-free, so the pre-state is
                // known exactly.
                app(TenantContext::class)->clear();
                Order::withoutGlobalScopes()->delete();

                // Two companies the user IS a member of, and one the user is NOT.
                $firstCompany = Company::factory()->create();
                $secondCompany = Company::factory()->create();
                $outsiderCompany = Company::factory()->create();

                // Seed each company's Orders directly (scope-free) so ownership
                // is explicit and the probe's expected visibility is exact.
                $firstIds = $this->seedOrders($firstCompany, $firstCount);
                $secondIds = $this->seedOrders($secondCompany, $secondCount);
                $this->seedOrders($outsiderCompany, $outsiderCount);

                // A scanner who is a member of the first and second companies
                // ONLY — membership, not a single home company, governs which
                // tenant a token may bind (Requirement 4.3).
                $user = User::factory()->for($firstCompany)->scanner()->create();
                $user->companies()->attach($firstCompany->id, ['role' => User::ROLE_SCANNER]);
                $user->companies()->attach($secondCompany->id, ['role' => User::ROLE_SCANNER]);

                // Issue a real token with NO Active_Company yet, exactly as the
                // AuthController hands a multi-membership user before they pick.
                $plainTextToken = $this->issueDeviceScopedToken($user);
                $tokenId = PersonalAccessToken::findToken($plainTextToken)->getKey();

                $headers = [
                    'Authorization' => "Bearer {$plainTextToken}",
                    'X-Device-Id' => self::DEVICE_ID,
                ];

                // --- MEMBER selection sets the Active_Company (Req 4.3) --------
                $this->postJson("/api/companies/{$firstCompany->id}/select", [], $headers)
                    ->assertOk()
                    ->assertJsonPath('company.id', $firstCompany->id);

                // The token now carries the first company as its Active_Company.
                $this->assertSame(
                    (int) $firstCompany->id,
                    (int) PersonalAccessToken::find($tokenId)->current_company_id,
                    'A member selection must stamp the token current_company_id.',
                );

                // A subsequent tenant-scoped request resolves the NEW company:
                // the probe sees exactly the first company's rows — never the
                // second's or the outsider's. (Req 4.3)
                $this->assertScopeResolvesTo($firstIds, $headers);

                // --- SWITCHING to a second membership re-scopes (Req 4.4) ------
                $this->postJson("/api/companies/{$secondCompany->id}/select", [], $headers)
                    ->assertOk()
                    ->assertJsonPath('company.id', $secondCompany->id);

                $this->assertSame(
                    (int) $secondCompany->id,
                    (int) PersonalAccessToken::find($tokenId)->current_company_id,
                    'Switching memberships must re-stamp the token current_company_id.',
                );

                // The probe now resolves the SECOND company only — re-scoping is
                // total, the first company's rows are no longer visible. (4.4)
                $this->assertScopeResolvesTo($secondIds, $headers);

                // --- NON-MEMBER selection is rejected and changes nothing -----
                // (Req 4.5)
                $this->postJson("/api/companies/{$outsiderCompany->id}/select", [], $headers)
                    ->assertForbidden();

                // The token's Active_Company is left exactly as it was — the
                // rejected selection never touched current_company_id.
                $this->assertSame(
                    (int) $secondCompany->id,
                    (int) PersonalAccessToken::find($tokenId)->current_company_id,
                    'A non-member selection must leave the token current_company_id unchanged.',
                );

                // And the previously-selected (second) company still governs
                // scope: the probe keeps resolving the second company's rows and
                // the outsider's rows never became visible. (4.5)
                $this->assertScopeResolvesTo($secondIds, $headers);
            });
    }

    /**
     * Assert that, under the token's current Active_Company, the tenant-scoped
     * probe sees exactly the given Order ids (sorted) and nothing else.
     *
     * @param  list<int>  $expectedIds
     * @param  array<string, string>  $headers
     */
    private function assertScopeResolvesTo(array $expectedIds, array $headers): void
    {
        $expected = collect($expectedIds)->sort()->values()->all();

        $this->getJson('/api/_tenant-probe/orders', $headers)
            ->assertOk()
            ->assertJsonPath('ids', $expected);
    }

    /**
     * Seed the given number of Orders for a Company, scope-free, returning their
     * ids so the probe's expected visibility can be asserted exactly.
     *
     * @return list<int>
     */
    private function seedOrders(Company $company, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Order::factory()->create(['company_id' => $company->id])->id;
        }

        return $ids;
    }

    /**
     * Issue a real Sanctum personal access token for the user bound to
     * {@see DEVICE_ID} and carrying NO Active_Company yet (`current_company_id`
     * null), mirroring what the AuthController issues to a multi-membership user
     * before they select a Company. Sanctum applies the configured
     * Token_Lifetime expiry automatically, so the token is valid for
     * `auth:sanctum`.
     *
     * @return string the plain-text token to present as a Bearer credential
     */
    private function issueDeviceScopedToken(User $user): string
    {
        $newToken = $user->createToken(self::DEVICE_ID);

        $newToken->accessToken->forceFill([
            'device_id' => self::DEVICE_ID,
        ])->save();

        return $newToken->plainTextToken;
    }
}
