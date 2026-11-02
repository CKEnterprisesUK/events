<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveApiTenant;
use App\Models\AuditLog;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\AuditLogger;
use App\Services\RoleAuthorization;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Session visibility and three-level token revocation for the native iOS
 * scanner app. A Session is exactly one `personal_access_tokens` row — Sanctum
 * is already the session ledger, so no new store is introduced. Each row's
 * `id`, device label (the token `name`), `last_used_at` and `created_at` are
 * surfaced, and the token the request itself authenticated with is flagged
 * `current`.
 *
 * Revocation is exposed at three authority levels, each with its authorisation
 * decided BEFORE any delete so a denied request leaves every token untouched
 * (Requirements 14.4, 14.5):
 *
 *   1. Self — {@see index()} and {@see destroy()} operate only on
 *      `$request->user()->tokens()`. A token id that is not one of the caller's
 *      own resolves to 404 (it is never visible), so a Staff_User can only see
 *      and revoke their own Sessions (Requirements 14.1, 14.2).
 *
 *   2. Team — {@see teamIndex()} and {@see teamDestroy()} carry the
 *      `ability:users` middleware and additionally re-check
 *      `Gate::authorize(ACTION_MANAGE_USERS)` (belt and braces, since abilities
 *      are minted at issue time). The target `{user}` MUST be a member of the
 *      Active_Company via the `company_user` pivot. A caller lacking
 *      `ACTION_MANAGE_USERS` → 403 (Requirement 14.4); a target who is not an
 *      Active_Company member → 403 (Requirement 14.5); tokens unchanged on
 *      either denial.
 *
 *   3. Super_Admin — a Super_Admin is not part of the Company matrix. The
 *      existing `Gate::before` bypass in
 *      {@see AppServiceProvider} returns `true` for
 *      `isSuperAdmin()`, so the same `Gate::authorize(ACTION_MANAGE_USERS)`
 *      passes cross-Company, and the Active_Company membership check is skipped
 *      for a Super_Admin actor — a Super_Admin may revoke any Staff_User's
 *      tokens cross-company (Requirement 14.6).
 *
 * Every successful revoke is `->delete()` on the row — after which
 * `auth:sanctum` rejects any later request bearing it (Requirement 14.7) — and
 * records a `TOKEN_REVOKED` Audit_Entry naming the acting actor and the target
 * Staff_User with scope `self|admin|super_admin`, context ids-only
 * (Requirement 16.2).
 */
class SessionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * List the authenticated user's own active Sessions.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $currentId = $user->currentAccessToken()?->getKey();

        return response()->json([
            'sessions' => $this->serialiseTokens($user, $currentId),
        ]);
    }

    /**
     * Revoke one of the authenticated user's own Sessions. A token id that does
     * not belong to the caller is never visible, so it resolves to 404.
     */
    public function destroy(Request $request, int $id): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|null $token */
        $token = $user->tokens()->whereKey($id)->first();

        if ($token === null) {
            abort(404);
        }

        $tokenId = $token->getKey();
        $token->delete();

        $this->recordRevocation($user, $tokenId, revokedBy: $user, scope: 'self');

        return response()->noContent();
    }

    /**
     * List a team member's active Sessions. Gated on `ACTION_MANAGE_USERS`
     * (with the super-admin bypass) and, for a non-super-admin actor, on the
     * target being an Active_Company member.
     */
    public function teamIndex(Request $request, User $user): JsonResponse
    {
        $this->authorizeTeamAccess($request, $user);

        return response()->json([
            'sessions' => $this->serialiseTokens($user, currentId: null),
        ]);
    }

    /**
     * Revoke one of a team member's Sessions. Authorisation is decided before
     * any delete, so a denied request leaves the target's tokens unchanged.
     */
    public function teamDestroy(Request $request, User $user, int $id): Response
    {
        $actor = $this->authorizeTeamAccess($request, $user);

        /** @var PersonalAccessToken|null $token */
        $token = $user->tokens()->whereKey($id)->first();

        if ($token === null) {
            abort(404);
        }

        $tokenId = $token->getKey();
        $token->delete();

        $scope = $actor->isSuperAdmin() ? 'super_admin' : 'admin';

        $this->recordRevocation($user, $tokenId, revokedBy: $actor, scope: $scope);

        return response()->noContent();
    }

    /**
     * Enforce the team-level authorisation and return the acting actor.
     *
     * The `ACTION_MANAGE_USERS` gate is re-checked here even though the route
     * carries `ability:users`: a Super_Admin passes via the `Gate::before`
     * bypass regardless of Company, and a Company role passes only when its
     * matrix permits it. For a non-super-admin actor the target must also be a
     * member of the Active_Company; a Super_Admin actor skips that membership
     * check so revocation reaches cross-company.
     */
    private function authorizeTeamAccess(Request $request, User $target): User
    {
        /** @var User $actor */
        $actor = $request->user();

        Gate::authorize(RoleAuthorization::ACTION_MANAGE_USERS);

        if (! $actor->isSuperAdmin() && ! $this->targetIsActiveCompanyMember($target)) {
            abort(403);
        }

        return $actor;
    }

    /**
     * Whether the target Staff_User is a member of the Active_Company bound by
     * {@see ResolveApiTenant} for this request. With no Active_Company bound
     * there is nothing to scope the check to, so membership cannot hold.
     */
    private function targetIsActiveCompanyMember(User $target): bool
    {
        $companyId = $this->tenantContext->companyId();

        if ($companyId === null) {
            return false;
        }

        return $target->companies()->whereKey($companyId)->exists();
    }

    /**
     * Serialise a user's tokens to the Sessions contract, flagging the token
     * that matches `$currentId` (if any) as the current Session.
     *
     * @return list<array{id:int, device_label:string|null, last_used_at:string|null, created_at:string|null, current:bool}>
     */
    private function serialiseTokens(User $user, int|string|null $currentId): array
    {
        return $user->tokens()->get()->map(fn (PersonalAccessToken $token) => [
            'id' => $token->getKey(),
            'device_label' => $token->name,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
            'current' => $currentId !== null && $token->getKey() === $currentId,
        ])->values()->all();
    }

    /**
     * Record a `TOKEN_REVOKED` Audit_Entry for a successful revoke: the target
     * Staff_User is the subject, the acting actor and scope are carried in the
     * ids-only context (never a token value) (Requirement 16.2).
     */
    private function recordRevocation(User $target, int|string $tokenId, User $revokedBy, string $scope): void
    {
        $this->audit->record(
            action: AuditLog::TOKEN_REVOKED,
            auditable: $target,
            summary: 'Revoked an API access token',
            context: [
                'token_id' => $tokenId,
                'revoked_by' => $revokedBy->getKey(),
                'scope' => $scope,
            ],
        );
    }
}
