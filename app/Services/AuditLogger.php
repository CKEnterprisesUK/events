<?php

namespace App\Services;

use App\Http\Controllers\SuperAdmin\ImpersonationController;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes {@see AuditLog} rows for security-, money-, access-, and privacy-
 * sensitive actions. Callers pass only what is specific to the event (the
 * action key, the subject, a human summary, a small context payload); the
 * logger resolves the *actor context* — who, which Company, whether the actor
 * was a Super_Admin impersonating a tenant, and the request IP — from the
 * current request/session so it is never repeated at each call site.
 *
 * Impersonation detection is the accountability core: when a Super_Admin has
 * "jumped into" a Company (the `impersonate_company_id` session flag honoured by
 * {@see \App\Http\Middleware\ResolveDashboardTenant}), every action they take is
 * stamped `is_impersonated = true` with `impersonator_user_id` set, so staff
 * activity inside a tenant is always distinguishable from the organiser's own.
 *
 * The context payload MUST be PII-minimised: store references, ids and amounts
 * (in integer minor units), never customer names/emails or exported personal
 * data — see the GDPR call sites.
 */
class AuditLogger
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Record an action performed by the current authenticated actor (or the
     * system when unauthenticated). Actor, Company, impersonation and IP are
     * resolved automatically; pass the Company explicitly only when it cannot
     * be inferred from the tenant/subject (e.g. cross-tenant super-admin
     * actions such as suspending a specific Company).
     *
     * @param  array<string, mixed>  $context  PII-minimised structured payload.
     */
    public function record(
        string $action,
        ?Model $auditable = null,
        ?string $summary = null,
        array $context = [],
        ?int $companyId = null,
    ): AuditLog {
        $actor = Auth::user();
        $impersonatedCompanyId = $this->impersonatedCompanyId();

        return AuditLog::create([
            'company_id' => $companyId
                ?? $this->companyIdFor($auditable, $actor, $impersonatedCompanyId),
            'actor_user_id' => $actor?->getKey(),
            'actor_label' => $this->actorLabel($actor),
            'actor_type' => $this->actorType($actor, $impersonatedCompanyId !== null),
            'is_impersonated' => $impersonatedCompanyId !== null,
            'impersonator_user_id' => $impersonatedCompanyId !== null ? $actor?->getKey() : null,
            'action' => $action,
            'auditable_type' => $auditable !== null ? $auditable->getMorphClass() : null,
            'auditable_id' => $auditable?->getKey(),
            'summary' => $summary,
            'context' => $context === [] ? null : $context,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Record a system action with no acting user (webhook/queue context). The
     * Company must be supplied explicitly since there is no request tenant.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordSystem(
        string $action,
        ?Model $auditable = null,
        ?string $summary = null,
        array $context = [],
        ?int $companyId = null,
    ): AuditLog {
        return AuditLog::create([
            'company_id' => $companyId ?? $this->companyIdFromAuditable($auditable),
            'actor_user_id' => null,
            'actor_label' => 'System',
            'actor_type' => AuditLog::ACTOR_SYSTEM,
            'is_impersonated' => false,
            'impersonator_user_id' => null,
            'action' => $action,
            'auditable_type' => $auditable !== null ? $auditable->getMorphClass() : null,
            'auditable_id' => $auditable?->getKey(),
            'summary' => $summary,
            'context' => $context === [] ? null : $context,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * The Company id a Super_Admin is currently impersonating, or null. Only a
     * Super_Admin's session flag is honoured, mirroring
     * {@see \App\Http\Middleware\ResolveDashboardTenant}.
     */
    private function impersonatedCompanyId(): ?int
    {
        $actor = Auth::user();

        if (! $actor instanceof User || ! $actor->isSuperAdmin()) {
            return null;
        }

        $id = session(ImpersonationController::SESSION_KEY);

        return $id !== null ? (int) $id : null;
    }

    /**
     * Resolve the affected Company id, preferring the subject's own Company,
     * then the resolved request tenant, then the impersonated Company, then the
     * actor's own Company.
     */
    private function companyIdFor(?Model $auditable, ?User $actor, ?int $impersonatedCompanyId): ?int
    {
        $fromAuditable = $this->companyIdFromAuditable($auditable);

        if ($fromAuditable !== null) {
            return $fromAuditable;
        }

        if ($this->tenantContext->hasCompany()) {
            return $this->tenantContext->companyId();
        }

        if ($impersonatedCompanyId !== null) {
            return $impersonatedCompanyId;
        }

        return $actor?->company_id !== null ? (int) $actor->company_id : null;
    }

    /**
     * The `company_id` carried by a subject model, when it exposes one (a
     * Company-owned model, or the Company itself).
     */
    private function companyIdFromAuditable(?Model $auditable): ?int
    {
        if ($auditable === null) {
            return null;
        }

        if ($auditable instanceof Company) {
            return (int) $auditable->getKey();
        }

        $companyId = $auditable->getAttribute('company_id');

        return $companyId !== null ? (int) $companyId : null;
    }

    /**
     * A stable, human-readable snapshot of the actor at the time of the action,
     * so the trail still reads sensibly after a user is removed or anonymised.
     */
    private function actorLabel(?User $actor): string
    {
        if (! $actor instanceof User) {
            return 'System';
        }

        $name = trim((string) $actor->name);
        $email = trim((string) $actor->email);

        if ($name !== '' && $email !== '') {
            return "{$name} <{$email}>";
        }

        return $name !== '' ? $name : ($email !== '' ? $email : 'User #'.$actor->getKey());
    }

    /**
     * How to interpret the actor: an impersonating Super_Admin or a plain
     * Super_Admin is `super_admin`; an ordinary Company_User is `user`; no
     * authenticated user is `system`.
     */
    private function actorType(?User $actor, bool $isImpersonating): string
    {
        if (! $actor instanceof User) {
            return AuditLog::ACTOR_SYSTEM;
        }

        if ($isImpersonating || $actor->isSuperAdmin()) {
            return AuditLog::ACTOR_SUPER_ADMIN;
        }

        return AuditLog::ACTOR_USER;
    }
}
