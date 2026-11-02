<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\ScanEvent;
use App\Models\User;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Feature: ios-scanner-app
 *
 * Task 6.5 — Property 10: Revoked tokens are universally rejected.
 *
 * **Validates: Requirements 3.1, 3.3, 12.5**
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so `auth:sanctum`'s token lookup/rejection behaves
 * exactly as in production.
 *
 * {@see \App\Http\Controllers\Api\AuthController::logout()} revokes the token
 * the request authenticated with by deleting its `personal_access_tokens` row.
 * Once that row is gone, every later request bearing the same plain-text token
 * resolves no token at `auth:sanctum` and is rejected with a 401 authentication
 * error BEFORE any controller, tenant binding, or role gate runs (Requirements
 * 3.1, 3.3, 12.5).
 *
 * The token is issued the real way — a device-scoped Sanctum personal access
 * token presented as a Bearer header with the matching `X-Device-Id` — mirroring
 * {@see AuthController::issueToken()} (`current_company_id` carries the
 * Active_Company, `device_id` binds the Device). It is first confirmed to work
 * on a genuine authenticated route (`GET /api/auth/me`), then revoked via
 * `POST /api/auth/logout`.
 *
 * The property: after revocation, EVERY subsequent request presenting that same
 * token — generated across the whole authenticated API surface (`me`, `scan`,
 * `sessions`, `dashboard/live`, `customers`, and a second `logout`) — is
 * rejected with 401. And the rejection is total: no side effect occurs on a
 * denied request, proven by asserting a post-revocation `POST /api/scan`
 * attempt records no `scan_events` row (it never reaches the controller).
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class RevokedTokenRejectionTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The device the token is bound to. Every request presents this value as
     * `X-Device-Id` so device binding is never the reason for a rejection —
     * the ONLY reason a post-logout request is denied is the revoked token.
     */
    private const DEVICE_ID = 'device-ios-scanner-revoke';

    /**
     * The authenticated API routes the property ranges over. Each is a real
     * production route guarded by `auth:sanctum`; presenting a revoked token to
     * any of them must yield 401 regardless of method, tenant, or role gate.
     *
     * `sessions` and the second `logout` sit on `auth:sanctum` alone; `scan`,
     * `dashboard/live`, `customers` and `me` additionally run under
     * `api.tenant` — but `auth:sanctum` rejects the revoked token first in both
     * cases, so the downstream middleware never runs.
     *
     * @var array<string, array{method: string, uri: string}>
     */
    private const AUTHENTICATED_ROUTES = [
        'me' => ['method' => 'getJson', 'uri' => '/api/auth/me'],
        'scan' => ['method' => 'postJson', 'uri' => '/api/scan'],
        'sessions' => ['method' => 'getJson', 'uri' => '/api/sessions'],
        'dashboard' => ['method' => 'getJson', 'uri' => '/api/dashboard/live'],
        'customers' => ['method' => 'getJson', 'uri' => '/api/customers'],
        'logout_again' => ['method' => 'postJson', 'uri' => '/api/auth/logout'],
    ];

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 10: Revoked tokens are universally rejected — after logout, every
     * subsequent request presenting the same token across the authenticated API
     * surface is rejected 401, and no side effect (e.g. a scan_events row) is
     * ever produced by a rejected request.
     *
     * **Validates: Requirements 3.1, 3.3, 12.5**
     */
    // Feature: ios-scanner-app, Property 10: Revoked tokens are universally rejected — after logout, every subsequent request presenting the token is rejected with an authentication error
    public function test_revoked_token_is_rejected_on_every_authenticated_route(): void
    {
        $routeKeys = array_keys(self::AUTHENTICATED_ROUTES);

        $this->limitTo(self::MIN_ITERATIONS)
            ->forAll(
                // Which authenticated route the post-revocation request hits —
                // generated across the whole authenticated surface so the
                // property holds for every one of them, not just one.
                Generator\elements(...$routeKeys),
                // A generated QR payload for the `scan` case, so the side-effect
                // assertion ranges over varied input rather than a fixed string.
                Generator\string(),
            )
            ->then(function (string $routeKey, string $payload): void {
                // Each iteration starts from a clean slate — iterations share
                // the RefreshDatabase transaction. Clear any resolved tenant and
                // remove every Company-owned fixture scope-free so the pre-state
                // is known exactly.
                app(TenantContext::class)->clear();
                ScanEvent::withoutGlobalScopes()->delete();

                $company = Company::factory()->create();

                // The operator is a member of the Company the token is scoped to,
                // so the token genuinely authenticates (and resolves a tenant)
                // BEFORE revocation — the pre-logout success check is meaningful.
                $user = User::factory()->for($company)->scanner()->create();
                $user->companies()->attach($company->id, ['role' => User::ROLE_SCANNER]);

                $plainTextToken = $this->issueDeviceScopedToken($user, $company);

                $headers = [
                    'Authorization' => "Bearer {$plainTextToken}",
                    'X-Device-Id' => self::DEVICE_ID,
                ];

                // The token works on a real authenticated route before logout —
                // so a later 401 can only be the revocation, never a token that
                // was never valid.
                $this->getJson('/api/auth/me', $headers)->assertOk();

                // Revoke it: logout deletes the current access token's row.
                $this->postJson('/api/auth/logout', [], $headers)->assertNoContent();

                // The count of scan_events immediately before the rejected
                // request, so the side-effect assertion is a strict delta.
                $scanEventsBefore = ScanEvent::withoutGlobalScopes()->count();

                // Fire the generated authenticated route with the SAME revoked
                // token. Whatever route is chosen, `auth:sanctum` rejects it 401
                // before any controller/tenant/role logic runs. (3.1, 3.3, 12.5)
                $route = self::AUTHENTICATED_ROUTES[$routeKey];
                $method = $route['method'];

                $body = $routeKey === 'scan' ? ['payload' => $payload] : [];

                if ($method === 'getJson') {
                    $response = $this->getJson($route['uri'], $headers);
                } else {
                    $response = $this->postJson($route['uri'], $body, $headers);
                }

                $response->assertUnauthorized();

                // No side effect: a rejected request never reaches a controller,
                // so even the `scan` attempt records no scan_events row. The
                // count is byte-for-byte unchanged across every rejected route.
                $this->assertSame(
                    $scanEventsBefore,
                    ScanEvent::withoutGlobalScopes()->count(),
                    "A revoked-token request to /{$routeKey} must produce no side effect (no scan_events row).",
                );

                // Per-iteration cleanup — plain scoped deletes only, no DDL.
                app(TenantContext::class)->clear();
                ScanEvent::withoutGlobalScopes()->delete();
            });
    }

    /**
     * Issue a real Sanctum personal access token for the user, scoped to the
     * given Company and bound to {@see DEVICE_ID}, mirroring what
     * {@see \App\Http\Controllers\Api\AuthController::issueToken()} persists:
     * `current_company_id` carries the Active_Company and `device_id` binds the
     * token to its Device. Sanctum applies the configured Token_Lifetime expiry
     * automatically, so the freshly issued token is valid (non-expired) for
     * `auth:sanctum` until it is revoked by logout.
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
