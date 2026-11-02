<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DeviceTrustService;
use App\Services\RoleAuthorization;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Token-based authentication for the native iOS scanner app. Unlike the web
 * {@see LoginController}, this controller never
 * establishes a session: it validates credentials with `Auth::validate()` and
 * hands the client a Sanctum bearer token bound to the submitting Device.
 *
 * The flow mirrors the web two-step login but is stateless end to end:
 *   - {@see login()} checks the password. If the user has no active two-factor
 *     OR the submitting Device is already trusted, a token is issued straight
 *     away (Requirements 1.1, 1.4). Otherwise it returns `{status:"2fa_required"}`
 *     with NO token (Requirement 1.3) and the client must complete the challenge.
 *   - {@see twoFactor()} re-validates the password, verifies a TOTP or recovery
 *     code, trusts the Device, and issues the token (Requirements 2.1, 2.2);
 *     a bad code returns 422 and issues nothing (Requirement 2.3).
 *
 * Invalid credentials anywhere return a generic auth error and no token
 * (Requirement 1.2). Every issued token carries the user's permitted actions as
 * abilities, is bound to the issuing `device_id`, and self-expires at the
 * configured Token_Lifetime (Requirements 13.1, 13.3). Successful issuance is
 * audited `TOKEN_ISSUED`; logout is audited `TOKEN_REVOKED`; failed login/TOTP
 * reuse the web `AUTH_LOGIN_FAILED` / `AUTH_MFA_CHALLENGE_FAILED` entries, all
 * with PII-minimised context (ids/refs only — never the token, password, TOTP
 * or recovery code) (Requirements 16.1, 16.2, 16.4, 16.5).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DeviceTrustService $deviceTrust,
        private readonly TwoFactorAuthenticationService $twoFactor,
        private readonly RoleAuthorization $roles,
    ) {}

    /**
     * Step one: validate credentials and either issue a token or demand the
     * two-factor challenge. No session is created regardless of outcome.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_id' => ['required', 'string', 'max:255'],
        ]);

        $credentials = ['email' => $data['email'], 'password' => $data['password']];

        if (! Auth::validate($credentials)) {
            $this->auditFailedLogin($data['email']);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::getLastAttempted();

        // A two-factor user on an untrusted Device must prove the second factor
        // before any token is minted. The password alone grants nothing.
        if ($user->hasTwoFactorEnabled() && ! $this->deviceTrust->isTrusted($user, $data['device_id'])) {
            return response()->json(['status' => '2fa_required']);
        }

        $token = $this->issueToken($user, $data['device_id'], viaTrustedDevice: $user->hasTwoFactorEnabled());

        return response()->json([
            'token' => $token->plainTextToken,
            'permitted_actions' => $this->roles->permittedActions((string) $user->role),
        ]);
    }

    /**
     * Step two: re-validate credentials, verify the submitted TOTP or recovery
     * code, trust the Device, and issue a long-lived token. A failed challenge
     * returns 422 and issues nothing.
     */
    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_id' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $credentials = ['email' => $data['email'], 'password' => $data['password']];

        // Credentials are re-checked here: the two-factor step is a standalone
        // request and must not trust that the password was ever proven.
        if (! Auth::validate($credentials)) {
            $this->auditFailedLogin($data['email']);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::getLastAttempted();

        $code = $data['code'] ?? null;
        $recoveryCode = $data['recovery_code'] ?? null;

        if (($code === null || $code === '') && ($recoveryCode === null || $recoveryCode === '')) {
            throw ValidationException::withMessages([
                'code' => __('Enter your authentication code or a recovery code.'),
            ]);
        }

        if ($code !== null && $code !== '') {
            $passed = $this->twoFactor->verifyTotp($user, $code);
        } else {
            $passed = $this->twoFactor->useRecoveryCode($user, (string) $recoveryCode);
        }

        if (! $passed) {
            $this->audit->record(
                action: AuditLog::AUTH_MFA_CHALLENGE_FAILED,
                auditable: $user,
                summary: 'Failed API two-factor challenge',
                companyId: $user->company_id !== null ? (int) $user->company_id : null,
            );

            throw ValidationException::withMessages([
                'code' => __('That code is not valid. Try again, or use a recovery code.'),
            ]);
        }

        // Second factor proven: trust this Device so later sign-ins from it skip
        // the challenge (Requirements 2.1, 2.4), then issue the token.
        $this->deviceTrust->trust($user, $data['device_id'], null);

        $token = $this->issueToken($user, $data['device_id'], viaTrustedDevice: true);

        return response()->json([
            'token' => $token->plainTextToken,
            'permitted_actions' => $this->roles->permittedActions((string) $user->role),
        ]);
    }

    /**
     * Revoke the token the request authenticated with, so every later request
     * bearing it is rejected by `auth:sanctum` (Requirements 3.1, 3.3, 12.5).
     */
    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        $tokenId = $token->getKey();

        $token->delete();

        $this->audit->record(
            action: AuditLog::TOKEN_REVOKED,
            auditable: $user,
            summary: 'Revoked an API access token',
            context: [
                'token_id' => $tokenId,
                'revoked_by' => $user->getKey(),
                'scope' => 'logout',
            ],
        );

        return response()->noContent();
    }

    /**
     * The authenticated user, their permitted actions, and their Company
     * memberships — the payload the client uses to drive role-based UI and the
     * company picker (Requirements 8.4, 8.5, 4.2).
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $memberships = $user->companies()->get()->map(fn ($company) => [
            'id' => $company->getKey(),
            'name' => $company->name,
            'role' => $company->pivot->role,
        ])->values();

        return response()->json([
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'permitted_actions' => $this->roles->permittedActions((string) $user->role),
            'companies' => $memberships,
        ]);
    }

    /**
     * Mint a Device-bound, self-expiring token whose abilities are the user's
     * permitted actions. The expiry is pinned to the configured Token_Lifetime
     * and the issuing `device_id` is stored on the token row via `forceFill`
     * (the two columns Sanctum does not model), then a `TOKEN_ISSUED` entry is
     * recorded with PII-minimised context.
     */
    private function issueToken(User $user, string $deviceId, bool $viaTrustedDevice): NewAccessToken
    {
        $abilities = $this->roles->permittedActions((string) $user->role);

        $expiresAt = Carbon::now()->addMinutes((int) config('sanctum.expiration'));

        $token = $user->createToken(
            name: $deviceId,
            abilities: $abilities,
            expiresAt: $expiresAt,
        );

        // Bind the token to the issuing Device so `ResolveApiTenant` can reject
        // it when presented from any other Device (Requirement 13.4). The
        // `current_company_id` stays null here — a multi-membership user selects
        // the Active_Company via `CompanyController::select` after sign-in.
        $token->accessToken->forceFill([
            'device_id' => $deviceId,
            'expires_at' => $expiresAt,
        ])->save();

        $this->audit->record(
            action: AuditLog::TOKEN_ISSUED,
            auditable: $user,
            summary: 'Issued an API access token',
            context: [
                'token_id' => $token->accessToken->getKey(),
                'device_id' => $deviceId,
                'trusted' => $viaTrustedDevice,
            ],
            companyId: $user->company_id !== null ? (int) $user->company_id : null,
        );

        return $token;
    }

    /**
     * Audit a failed sign-in, but only when the email matches a real account —
     * so enumeration/credential-stuffing noise (already capped by the rate
     * limiter) does not bloat the trail. Context carries the email reference
     * only, never the submitted password (Requirement 16.5).
     */
    private function auditFailedLogin(string $email): void
    {
        $target = User::where('email', $email)->first();

        if ($target === null) {
            return;
        }

        $this->audit->record(
            action: AuditLog::AUTH_LOGIN_FAILED,
            auditable: $target,
            summary: 'Failed API sign-in for '.$target->email,
            context: ['email' => $target->email],
            companyId: $target->company_id !== null ? (int) $target->company_id : null,
        );
    }
}
