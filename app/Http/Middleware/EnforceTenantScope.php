<?php

namespace App\Http\Middleware;

use App\Models\Concerns\BelongsToCompany;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bounds the global `company_id` query scope to a single request lifecycle.
 *
 * The scope itself is registered on every Company-owned model via the
 * {@see BelongsToCompany} trait, which reads the active
 * Company from {@see TenantContext}. This middleware guarantees the two
 * lifecycle halves the design calls for:
 *   - it runs ahead of {@see ResolveTenant} on the group so the scope is active
 *     for the whole request (constraining every read/write/update/delete to the
 *     resolved `company_id`), and (Requirement 1.4)
 *   - it clears the resolved Company from {@see TenantContext} once the response
 *     is produced, so no tenant leaks into the next request handled by the same
 *     worker and unresolved requests establish no Company. (Requirements 1.5, 1.7)
 */
class EnforceTenantScope
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } finally {
            // End of the request lifecycle: drop the resolved tenant so the
            // scope reverts to denying Company-owned records until the next
            // request re-resolves one.
            $this->tenantContext->clear();
        }
    }
}
