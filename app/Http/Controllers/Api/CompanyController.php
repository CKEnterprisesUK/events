<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveApiTenant;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company selection and switching for the native iOS scanner app. A multi-
 * membership Staff_User signs in with a token that carries no Active_Company
 * yet ({@see AuthController::issueToken()} leaves `current_company_id` null);
 * this controller lists the Companies the user belongs to and lets them pick
 * the one a token is scoped to.
 *
 * Selection is driven entirely by the `company_user` pivot (Requirement 4.3):
 *   - {@see index()} returns every membership — `{id, name, role}` — so the
 *     client can present the company picker (Requirements 4.2, 4.6).
 *   - {@see select()} binds the chosen Company onto the request's own token:
 *     it verifies membership, stamps `current_company_id`, and re-mints the
 *     token's abilities (the role's permitted actions plus `company:{id}`) so
 *     {@see ResolveApiTenant} resolves the new tenant on
 *     every later request (Requirements 4.3, 4.4). A non-member is rejected
 *     403 and the token's Active_Company is left exactly as it was
 *     (Requirement 4.5). A successful select/switch records a
 *     `COMPANY_SELECTED` Audit_Entry naming the chosen Company, with ids-only
 *     context (Requirement 16.3).
 *
 * Unlike the tenant-scoped endpoints, `select` runs under `auth:sanctum`
 * WITHOUT `api.tenant`: selecting is the act that *sets* the Active_Company, so
 * it must not require one to already be bound.
 */
class CompanyController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RoleAuthorization $roles,
    ) {}

    /**
     * List the authenticated user's Company memberships from `company_user`,
     * each carrying the per-Company role so the client can drive the picker.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $memberships = $user->companies()->get()->map(fn (Company $company) => [
            'id' => $company->getKey(),
            'name' => $company->name,
            'role' => $company->pivot->role,
        ])->values();

        return response()->json(['companies' => $memberships]);
    }

    /**
     * Select (or switch to) the Active_Company for the request's own token.
     *
     * Membership is the gate: a user who is not a member of the given Company
     * is rejected 403 and the token's `current_company_id`/abilities are left
     * untouched. On success the token is stamped with the Company id and its
     * abilities are re-minted to the member's role permitted actions plus the
     * `company:{id}` ability, so the next request resolves the new tenant.
     */
    public function select(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Company|null $company */
        $company = $user->companies()->whereKey($id)->first();

        // Non-member: reject without touching the token's Active_Company.
        if ($company === null) {
            abort(403);
        }

        $role = (string) $company->pivot->role;
        $abilities = $this->roles->permittedActions($role);
        $abilities[] = 'company:'.$company->getKey();

        $token = $user->currentAccessToken();

        $token->forceFill([
            'current_company_id' => $company->getKey(),
            'abilities' => $abilities,
        ])->save();

        $this->audit->record(
            action: AuditLog::COMPANY_SELECTED,
            auditable: $company,
            summary: 'Selected active company '.$company->name,
            context: ['company_id' => $company->getKey()],
            companyId: (int) $company->getKey(),
        );

        return response()->json([
            'company' => [
                'id' => $company->getKey(),
                'name' => $company->name,
                'role' => $role,
            ],
            'permitted_actions' => $this->roles->permittedActions($role),
        ]);
    }
}
