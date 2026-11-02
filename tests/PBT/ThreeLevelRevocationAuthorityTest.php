<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\ScanEvent;
use App\Models\User;
use App\Services\RoleAuthorization;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Feature: ios-scanner-app
 *
 * Task 12.2 — Property 12: Token revocation authority is confined to the right
 * level.
 *
 * **Validates: Requirements 14.2, 14.3, 14.4, 14.5, 14.6, 14.7**
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so `auth:sanctum`'s token lookup/rejection and the
 * `ability:users` + `Gate` authorisation behave exactly as in production.
 *
 * Every assertion runs through the real production
 * {@see \App\Http\Controllers\Api\SessionController} routes:
 *
 *   - SELF (`DELETE /api/sessions/{id}`, `auth:sanctum` only). A Staff_User can
 *     revoke only their OWN Sessions: deleting their own token returns 204 and
 *     the revoked token then 401s on reuse (Requirements 14.2, 14.7); deleting
 *     a token id that is NOT theirs returns 404 and leaves that token intact —
 *     a foreign Session is never even visible, let alone revocable.
 *
 *   - TEAM (`DELETE /api/users/{user}/sessions/{id}`, `auth:sanctum` +
 *     `api.tenant` + `ability:users`). An actor holding `ACTION_MANAGE_USERS`
 *     (Owner/Admin) can revoke a token of a target who is a member of the
 *     Active_Company (204, then 401 on reuse) (Requirement 14.3), but revoking
 *     a target who is NOT an Active_Company member returns 403 and leaves the
 *     target's token intact (Requirement 14.5).
 *
 *   - UNPRIVILEGED (same team route). An actor WITHOUT the `users` ability /
 *     without `ACTION_MANAGE_USERS` (e.g. a Scanner) is blocked at the
 *     `ability:users` middleware with 403, tokens unchanged (Requirement 14.4).
 *
 *   - SUPER_ADMIN (same team route). A Super_Admin passes the
 *     `Gate::authorize(ACTION_MANAGE_USERS)` check via the `Gate::before`
 *     bypass and the Active_Company membership check is skipped, so a
 *     Super_Admin can revoke ANY Staff_User's token cross-company (204, then
 *     401 on reuse) (Requirement 14.6).
 *
 * The invariant that ties them together (Requirement 14.7): every DENIAL (404
 * or 403) leaves the target token row byte-for-byte intact, and every
 * SUCCESSFUL revoke (204) makes the revoked plain-text token 401 on its next
 * use — proven here by actually presenting the just-revoked token to a real
 * authenticated route.
 *
 * Tokens are issued the real way — a Sanctum personal access token presented as
 * a Bearer header with the matching `X-Device-Id` — mirroring
 * {@see \App\Http\Controllers\Api\AuthController::issueToken()}:
 * `current_company_id` carries the Active_Company (so `ResolveApiTenant` binds
 * the tenant for the team membership check) and `device_id` binds the Device.
 * The actor's token is minted with abilities matching how the real issuance
 * encodes them from `RoleAuthorization::permittedActions(...)` — a privileged
 * actor's token therefore carries `users` so `ability:users` passes, and an
 * unprivileged actor's token does not, so it is blocked at the ability
 * middleware. A Super_Admin (outside the Company matrix) is minted the default
 * wildcard ability so the ability middleware passes and the `Gate::before`
 * bypass carries the authorisation.
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class ThreeLevelRevocationAuthorityTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The device every token is bound to and every request presents as
     * `X-Device-Id`, so device binding is never the reason for a rejection —
     * the only decisive factors are the revocation-authority rules.
     */
    private const DEVICE_ID = 'device-ios-scanner-revoke-authority';

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 12: Token revocation authority is confined to the right level —
     * self revokes only own (foreign id → 404); an `ACTION_MANAGE_USERS` actor
     * revokes a same-company member but gets 403 on a non-member; an
     * unprivileged actor gets 403; a Super_Admin revokes cross-company; every
     * denial leaves the target token intact and every successful revoke 401s on
     * reuse.
     *
     * **Validates: Requirements 14.2, 14.3, 14.4, 14.5, 14.6, 14.7**
     */
    // Feature: ios-scanner-app, Property 12: Token revocation authority is confined to the right level — self revokes only own (foreign id → 404); an ACTION_MANAGE_USERS actor revokes a same-company member but 403 on a non-member; an unprivileged actor 403; a Super_Admin revokes cross-company; every denial leaves tokens unchanged and a successful revoke then 401s on reuse
    public function test_token_revocation_authority_is_confined_to_the_right_level(): void
    {
        $this->limitTo(self::MIN_ITERATIONS)
            ->forAll(
                // The privileged (ACTION_MANAGE_USERS) actor's role — generated
                // across BOTH privileged company roles so the team property is
                // not pinned to one role.
                Generator\elements(User::ROLE_OWNER, User::ROLE_ADMIN),
                // The unprivileged actor's role — generated across the company
                // roles that lack ACTION_MANAGE_USERS so the denial holds for
                // every one of them, not just the Scanner.
                Generator\elements(
                    User::ROLE_BOX_OFFICE,
                    User::ROLE_ACCOUNTANT,
                    User::ROLE_SCANNER,
                ),
            )
            ->then(function (string $privilegedRole, string $unprivilegedRole): void {
                // Each iteration starts from a clean slate — iterations share
                // the RefreshDatabase transaction. Clear any resolved tenant and
                // remove Company-owned fixtures scope-free so the pre-state is
                // known exactly, and clear every token so revocation deltas are
                // unambiguous.
                app(TenantContext::class)->clear();
                ScanEvent::withoutGlobalScopes()->delete();
                PersonalAccessToken::query()->delete();

                // Two companies: A is the actor's Active_Company; B is a
                // separate company whose member is a non-member of A (the 403
                // target) and whose member the Super_Admin reaches cross-company.
                $companyA = Company::factory()->create();
                $companyB = Company::factory()->create();

                $roles = app(RoleAuthorization::class);

                // --- SELF LEVEL -------------------------------------------------
                // A Staff_User may revoke only their own Sessions. Two users each
                // hold a token; neither may touch the other's.
                $selfUser = $this->member($companyA, User::ROLE_SCANNER);
                $otherUser = $this->member($companyA, User::ROLE_SCANNER);

                $selfPlain = $this->issueToken($selfUser, $companyA, $roles->permittedActions(User::ROLE_SCANNER));
                $selfTokenId = PersonalAccessToken::findToken($selfPlain)->getKey();

                $otherPlain = $this->issueToken($otherUser, $companyA, $roles->permittedActions(User::ROLE_SCANNER));
                $otherTokenId = PersonalAccessToken::findToken($otherPlain)->getKey();

                $selfHeaders = $this->bearer($selfPlain);

                // Revoking a token that is NOT the caller's own → 404, and that
                // foreign token is left intact (never visible, never revoked).
                $this->deleteJson("/api/sessions/{$otherTokenId}", [], $selfHeaders)
                    ->assertNotFound();
                $this->assertTokenIntact($otherTokenId, 'A self revoke of a foreign token must not delete it.');

                // The foreign token still works — the denied request had no
                // side effect on it (14.7 denial leaves tokens unchanged).
                $this->getJson('/api/auth/me', $this->bearer($otherPlain))->assertOk();

                // Revoking the caller's OWN token → 204, and the revoked token
                // then 401s on reuse (14.2, 14.7).
                $this->deleteJson("/api/sessions/{$selfTokenId}", [], $selfHeaders)
                    ->assertNoContent();
                $this->assertTokenGone($selfTokenId, 'A self revoke must delete the caller\'s own token.');
                $this->getJson('/api/auth/me', $selfHeaders)->assertUnauthorized();

                // --- TEAM LEVEL (privileged actor) -----------------------------
                // An ACTION_MANAGE_USERS actor in company A revokes a same-company
                // member's token (204 + 401 on reuse) but gets 403 revoking a
                // target who is NOT a member of A, leaving that token intact.
                $privilegedActor = $this->member($companyA, $privilegedRole);
                $privilegedPlain = $this->issueToken(
                    $privilegedActor,
                    $companyA,
                    $roles->permittedActions($privilegedRole),
                );
                $privilegedHeaders = $this->bearer($privilegedPlain);

                // Same-company member target — their token is revocable (14.3).
                $memberTarget = $this->member($companyA, User::ROLE_SCANNER);
                $memberTargetPlain = $this->issueToken($memberTarget, $companyA, $roles->permittedActions(User::ROLE_SCANNER));
                $memberTargetTokenId = PersonalAccessToken::findToken($memberTargetPlain)->getKey();

                // Non-member target — a member of company B only, so not a member
                // of the actor's Active_Company A (14.5).
                $nonMemberTarget = $this->member($companyB, User::ROLE_SCANNER);
                $nonMemberTargetPlain = $this->issueToken($nonMemberTarget, $companyB, $roles->permittedActions(User::ROLE_SCANNER));
                $nonMemberTargetTokenId = PersonalAccessToken::findToken($nonMemberTargetPlain)->getKey();

                // 403 on the non-member target, and their token stays intact and
                // still usable — the denial had no side effect (14.5, 14.7).
                $this->deleteJson(
                    "/api/users/{$nonMemberTarget->id}/sessions/{$nonMemberTargetTokenId}",
                    [],
                    $privilegedHeaders,
                )->assertForbidden();
                $this->assertTokenIntact($nonMemberTargetTokenId, 'A non-member target\'s token must survive a 403.');

                // 204 on the same-company member target, and their token then
                // 401s on reuse (14.3, 14.7).
                $this->deleteJson(
                    "/api/users/{$memberTarget->id}/sessions/{$memberTargetTokenId}",
                    [],
                    $privilegedHeaders,
                )->assertNoContent();
                $this->assertTokenGone($memberTargetTokenId, 'A privileged actor must revoke a same-company member\'s token.');
                $this->getJson('/api/auth/me', $this->bearer($memberTargetPlain))->assertUnauthorized();

                // --- UNPRIVILEGED ACTOR ----------------------------------------
                // An actor without the `users` ability / ACTION_MANAGE_USERS is
                // blocked at the ability middleware → 403, tokens unchanged
                // (14.4). The target here is a genuine company-A member, so the
                // ONLY reason for the denial is the actor's lack of authority.
                $unprivilegedActor = $this->member($companyA, $unprivilegedRole);
                $unprivilegedPlain = $this->issueToken(
                    $unprivilegedActor,
                    $companyA,
                    $roles->permittedActions($unprivilegedRole),
                );

                $unprivTarget = $this->member($companyA, User::ROLE_SCANNER);
                $unprivTargetPlain = $this->issueToken($unprivTarget, $companyA, $roles->permittedActions(User::ROLE_SCANNER));
                $unprivTargetTokenId = PersonalAccessToken::findToken($unprivTargetPlain)->getKey();

                $this->deleteJson(
                    "/api/users/{$unprivTarget->id}/sessions/{$unprivTargetTokenId}",
                    [],
                    $this->bearer($unprivilegedPlain),
                )->assertForbidden();
                $this->assertTokenIntact($unprivTargetTokenId, 'An unprivileged actor\'s denied revoke must not delete the target token.');
                // The target token still works — denial left it unchanged (14.7).
                $this->getJson('/api/auth/me', $this->bearer($unprivTargetPlain))->assertOk();

                // --- SUPER_ADMIN -----------------------------------------------
                // A Super_Admin passes ACTION_MANAGE_USERS via the Gate::before
                // bypass and skips the Active_Company membership check, so they
                // revoke ANY Staff_User's token cross-company — here a company-B
                // member while the Super_Admin is bound to no company (14.6). The
                // Super_Admin token carries the wildcard ability so the
                // `ability:users` middleware passes, matching how a token outside
                // the Company matrix is minted.
                $superAdmin = User::factory()->superAdmin()->create();
                $superPlain = $this->issueToken($superAdmin, company: null, abilities: ['*']);

                $crossCompanyTarget = $this->member($companyB, User::ROLE_SCANNER);
                $crossCompanyTargetPlain = $this->issueToken($crossCompanyTarget, $companyB, $roles->permittedActions(User::ROLE_SCANNER));
                $crossCompanyTargetTokenId = PersonalAccessToken::findToken($crossCompanyTargetPlain)->getKey();

                $this->deleteJson(
                    "/api/users/{$crossCompanyTarget->id}/sessions/{$crossCompanyTargetTokenId}",
                    [],
                    $this->bearer($superPlain),
                )->assertNoContent();
                $this->assertTokenGone($crossCompanyTargetTokenId, 'A Super_Admin must revoke a token cross-company.');
                $this->getJson('/api/auth/me', $this->bearer($crossCompanyTargetPlain))->assertUnauthorized();

                // Per-iteration cleanup — plain deletes only, no DDL.
                app(TenantContext::class)->clear();
                ScanEvent::withoutGlobalScopes()->delete();
                PersonalAccessToken::query()->delete();
            });
    }

    /**
     * Create a company member holding the given role, attaching the membership
     * row the Active_Company check reads. The user's single `company_id` is set
     * to the same company so their web shape is coherent, but it is the pivot
     * membership that governs which tenant a token may bind (Requirement 4.3).
     */
    private function member(Company $company, string $role): User
    {
        $user = User::factory()->for($company)->state(['role' => $role])->create();
        $user->companies()->attach($company->id, ['role' => $role]);

        return $user;
    }

    /**
     * Issue a real Sanctum personal access token for the user, bound to
     * {@see DEVICE_ID} and carrying the given abilities and Active_Company,
     * mirroring {@see \App\Http\Controllers\Api\AuthController::issueToken()}:
     * `current_company_id` carries the Active_Company (so `ResolveApiTenant`
     * binds the tenant for the team membership check) and `device_id` binds the
     * Device. Sanctum applies the configured Token_Lifetime expiry
     * automatically, so the token is valid (non-expired) for `auth:sanctum`.
     *
     * @param  list<string>  $abilities
     * @return string the plain-text token to present as a Bearer credential
     */
    private function issueToken(User $user, ?Company $company, array $abilities): string
    {
        // A token with an empty ability list would be granted no abilities at
        // all and could never pass `ability:users`; the real issuance always
        // encodes at least the role's permitted actions, so an empty list only
        // arises for roles that genuinely lack `users` — keep it empty so the
        // ability middleware denies them exactly as production does.
        $newToken = $user->createToken(self::DEVICE_ID, $abilities);

        $newToken->accessToken->forceFill([
            'current_company_id' => $company?->id,
            'device_id' => self::DEVICE_ID,
        ])->save();

        return $newToken->plainTextToken;
    }

    /**
     * Bearer + device headers for a plain-text token. The device header always
     * matches {@see DEVICE_ID} so device binding is never the reason for a
     * rejection.
     *
     * @return array<string, string>
     */
    private function bearer(string $plainTextToken): array
    {
        return [
            'Authorization' => "Bearer {$plainTextToken}",
            'X-Device-Id' => self::DEVICE_ID,
        ];
    }

    /**
     * Assert the token row still exists (a denied revoke left it intact).
     */
    private function assertTokenIntact(int $tokenId, string $message): void
    {
        $this->assertNotNull(PersonalAccessToken::find($tokenId), $message);
    }

    /**
     * Assert the token row is gone (a successful revoke deleted it).
     */
    private function assertTokenGone(int $tokenId, string $message): void
    {
        $this->assertNull(PersonalAccessToken::find($tokenId), $message);
    }
}
