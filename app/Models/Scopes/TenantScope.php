<?php

namespace App\Models\Scopes;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Eloquent scope that constrains every query on a Company-owned model to
 * the Company resolved for the current request.
 *
 * The scope reads the active Company from {@see TenantContext}:
 *   - WHILE a Company is resolved, every read/write/update/delete gains a
 *     `WHERE company_id = :resolved` clause, so cross-Company rows never match
 *     and surface as "not found" with no modification. (Requirements 1.4, 1.5)
 *   - WHEN no Company is resolved (reserved prefixes, unmatched slug), the scope
 *     forces an impossible predicate so Company-owned records are inaccessible.
 *     (Requirement 1.7)
 *
 * `ResolveTenant` establishes the tenant and `EnforceTenantScope` clears it at
 * the end of the request, so the scope is inert outside a resolved request.
 */
class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $column = $model->qualifyColumn('company_id');

        if ($context->hasCompany()) {
            // Constrain to the resolved tenant. (Requirements 1.4, 1.5)
            $builder->where($column, $context->companyId());

            return;
        }

        // No active Company established: deny access to all Company-owned
        // records with an unsatisfiable predicate. (Requirement 1.7)
        $builder->whereRaw('1 = 0');
    }
}
