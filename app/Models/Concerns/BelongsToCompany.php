<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Scopes\TenantScope;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every Company-owned model (rows carrying `company_id`) to enforce
 * tenant isolation.
 *
 * Booting the trait:
 *   - Registers the global {@see TenantScope}, so every query is constrained to
 *     the Company resolved for the request (or denied when none is resolved).
 *     (Requirements 1.4, 1.5, 1.7)
 *   - Auto-fills `company_id` from {@see TenantContext} on create when a tenant
 *     is resolved and the attribute has not been set explicitly, so new rows
 *     always belong to the active Company. (Requirement 1.1)
 *
 * The {@see Company} model itself must NOT use this trait: it is the tenant
 * root, resolved by slug before any tenant is established.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('company_id') !== null) {
                return;
            }

            $context = app(TenantContext::class);

            if ($context->hasCompany()) {
                $model->setAttribute('company_id', $context->companyId());
            }
        });
    }

    /**
     * The Company that owns this record.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
