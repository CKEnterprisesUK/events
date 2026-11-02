<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the Active_Company from the authenticated token (and enforces the
 * token's device binding) for every tenant-scoped API route, mirroring the web
 * {@see ResolveDashboardTenant} but driven by the resolving token rather than
 * `user.company_id`.
 *
 * Runs inside the `api.tenant` group after `auth:sanctum` (so the token is
 * already present and non-expired — `auth:sanctum` rejects expired/revoked
 * tokens upstream). {@see EnforceTenantScope} clears the resolved tenant once
 * the response is produced.
 *
 * Two guarantees, in order:
 *   1. Device binding (Requirement 13.4) — the request's `X-Device-Id` header
 *      is compared against the resolving token's bound `device_id`. On a
 *      mismatch the request is rejected with 401 BEFORE any Company query runs,
 *      so a stolen token presented from a different Device resolves no tenant
 *      and triggers no side effects.
 *   2. Tenant binding (Requirements 4.1, 4.3, 12.3) — the token's
 *      `current_company_id` is bound onto {@see TenantContext} only after
 *      verifying the authenticated user is a member of that Company via the
 *      `company_user` pivot. A token that has not selected a Company yet
 *      (`current_company_id` is null), a non-member, or a suspended Company
 *      establishes no tenant — consistent with the web "no company" path, the
 *      global `company_id` scope then denies every Company-owned record.
 */
class ResolveApiTenant
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $this->tenantContext->hasCompany()) {
            $token = $user->currentAccessToken();

            // Device binding comes first: reject a token presented from a
            // Device other than the one it was issued to before any Company
            // lookup, so a mismatch has no tenant-resolving side effect.
            if ($token !== null && $token->device_id !== null
                && $request->header('X-Device-Id') !== $token->device_id) {
                abort(401);
            }

            $companyId = $token?->current_company_id;

            if ($companyId !== null && $this->userBelongsToCompany($user, (int) $companyId)) {
                $company = Company::find($companyId);

                if ($company !== null && ! $company->isSuspended()) {
                    $this->tenantContext->setCompany($company);
                }
            }
        }

        return $next($request);
    }

    /**
     * Whether the authenticated user is a member of the given Company via the
     * `company_user` pivot — membership, not the single web `company_id`,
     * governs which Company a token may bind. (Requirement 4.3)
     */
    private function userBelongsToCompany($user, int $companyId): bool
    {
        return $user->companies()
            ->whereKey($companyId)
            ->exists();
    }
}
