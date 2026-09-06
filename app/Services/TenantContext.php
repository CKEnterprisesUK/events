<?php

namespace App\Services;

use App\Models\Company;

/**
 * Holds the Company resolved for the current request.
 *
 * `ResolveTenant` middleware sets the active Company after matching the leading
 * slug segment; the global `company_id` Eloquent scope reads `companyId()` to
 * constrain every query to the resolved tenant. When no Company is resolved
 * (reserved prefixes, unmatched slug), the context is empty and
 * Company-owned records are inaccessible.
 *
 * Registered as a singleton for the request lifecycle so all collaborators see
 * the same resolved tenant.
 */
class TenantContext
{
    private ?Company $company = null;

    /**
     * Set the active Company for the request.
     */
    public function setCompany(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * The resolved Company, or null when no tenant is established.
     */
    public function company(): ?Company
    {
        return $this->company;
    }

    /**
     * Whether a Company has been resolved for the request.
     */
    public function hasCompany(): bool
    {
        return $this->company !== null;
    }

    /**
     * The resolved Company's id, or null when no tenant is established.
     *
     * Consumed by the global `company_id` query scope.
     */
    public function companyId(): ?int
    {
        return $this->company?->getKey();
    }

    /**
     * Clear the resolved Company (end of request lifecycle).
     */
    public function clear(): void
    {
        $this->company = null;
    }
}
