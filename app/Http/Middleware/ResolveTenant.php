<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active Company from the leading `/{company-slug}/` path segment.
 *
 * Behaviour (see design "Request and Tenancy Flow"):
 *   - Reserved prefixes (`/`, `/dashboard`, `/admin`) skip slug resolution and
 *     establish no Company. (Requirement 1.7)
 *   - Otherwise the leading segment is matched against `companies.slug` using a
 *     case-insensitive comparison, within the 200ms budget via the UNIQUE slug
 *     index plus a short-lived per-slug cache. (Requirement 1.2)
 *   - IF no Company matches, THEN a 404 is returned and no active Company is
 *     established. (Requirement 1.3)
 *   - IF the resolved Company is suspended, THEN a 404 is returned (storefront /
 *     event access is denied). (Requirements 2.1, 2.2)
 *   - Otherwise the Company is bound into {@see TenantContext} so the global
 *     `company_id` scope constrains every subsequent query. (Requirement 1.4)
 */
class ResolveTenant
{
    /**
     * Path prefixes that never carry a Company_Slug segment and therefore
     * establish no active Company.
     *
     * @var list<string>
     */
    public const RESERVED_PREFIXES = ['dashboard', 'admin'];

    /**
     * How long a resolved slug -> Company mapping is cached, keeping repeated
     * lookups within the 200ms budget without serving stale suspension state
     * for long. (Requirement 1.2)
     */
    private const CACHE_TTL_SECONDS = 10;

    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->leadingSegment($request);

        // Root (`/`) and reserved dashboard/admin prefixes are not storefronts:
        // establish no active Company. (Requirement 1.7)
        if ($slug === null || $this->isReservedPrefix($slug)) {
            return $next($request);
        }

        $company = $this->resolveCompany($slug);

        // No Company matches the requested slug: deny with 404 and establish no
        // active Company. (Requirement 1.3)
        if ($company === null) {
            abort(404);
        }

        // A suspended Company's storefront and event pages are not available.
        // (Requirements 2.1, 2.2)
        if ($company->isSuspended()) {
            abort(404);
        }

        $this->tenantContext->setCompany($company);

        return $next($request);
    }

    /**
     * The first path segment of the request, or null for the root path.
     */
    private function leadingSegment(Request $request): ?string
    {
        return $request->segment(1);
    }

    /**
     * Whether the leading segment is a reserved (non-tenant) prefix.
     */
    private function isReservedPrefix(string $segment): bool
    {
        return in_array(strtolower($segment), self::RESERVED_PREFIXES, true);
    }

    /**
     * Resolve an active-or-suspended Company by case-insensitive slug match.
     *
     * The lookup uses `LOWER(slug) = LOWER(?)` against the UNIQUE slug index and
     * is memoised for a short window so repeat requests stay within the 200ms
     * budget. Slugs are stored lowercase (Requirement 1.6), so this matches any
     * letter-case the caller supplies. (Requirement 1.2)
     */
    private function resolveCompany(string $slug): ?Company
    {
        $normalised = strtolower($slug);

        // Cache only the resolved Company id (a scalar), never the Eloquent
        // model itself. Serialising full models to the cache store leaves a
        // "__PHP_Incomplete_Class" on read whenever the class can't be resolved
        // at unserialize time, which breaks the ?Company return contract.
        $companyId = cache()->remember(
            "tenant:slug:{$normalised}",
            self::CACHE_TTL_SECONDS,
            fn () => Company::query()
                ->whereRaw('LOWER(slug) = ?', [$normalised])
                ->value('id')
        );

        if ($companyId === null) {
            return null;
        }

        $company = Company::query()->whereKey($companyId)->first();

        // The cached id may point at a Company that has since been deleted;
        // treat that as an unresolved slug rather than returning null-typed
        // garbage from the cache.
        if ($company === null) {
            cache()->forget("tenant:slug:{$normalised}");
        }

        return $company;
    }
}
