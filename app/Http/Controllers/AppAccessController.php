<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RoleAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Revokes a user's long-lived iOS app tokens ("app access") from the web.
 *
 * - Owners/Admins (ACTION_MANAGE_USERS) may revoke any member of their own
 *   Company.
 * - Any user may revoke their own.
 * - A Super_Admin may revoke anyone's, across Companies.
 *
 * Revoking deletes every token, so the app is signed out on its next request
 * and the user must sign in again.
 */
class AppAccessController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Own app access, from the profile / team page. */
    public function revokeOwn(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $count = $this->revokeAll($user, 'self');

        return back()->with('status', $this->message($count, 'your account'));
    }

    /** A Company member's app access, by an Owner/Admin of that Company. */
    public function revokeForMember(Request $request, User $user): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_USERS);

        /** @var User $actor */
        $actor = $request->user();

        abort_unless(
            $actor->company_id !== null && (int) $user->company_id === (int) $actor->company_id,
            404,
        );

        $count = $this->revokeAll($user, 'admin');

        return back()->with('status', $this->message($count, $user->email));
    }

    /** Any user's app access, by a Super_Admin. */
    public function revokeForAdmin(Request $request, int $user): RedirectResponse
    {
        $target = User::query()->withoutGlobalScopes()->where('is_super_admin', false)->findOrFail($user);

        $count = $this->revokeAll($target, 'super_admin');

        return back()->with('status', $this->message($count, $target->email));
    }

    private function revokeAll(User $target, string $scope): int
    {
        $actor = auth()->user();
        $ids = $target->tokens()->pluck('id');

        foreach ($ids as $tokenId) {
            $this->audit->record(
                action: AuditLog::TOKEN_REVOKED,
                auditable: $target,
                summary: 'Revoked an API access token',
                context: [
                    'token_id' => $tokenId,
                    'revoked_by' => $actor?->getKey(),
                    'scope' => $scope,
                ],
                companyId: $target->company_id !== null ? (int) $target->company_id : null,
            );
        }

        $target->tokens()->delete();

        return $ids->count();
    }

    private function message(int $count, string $who): string
    {
        return $count === 0
            ? "No active app sign-ins for {$who}."
            : "App access revoked for {$who} ({$count} ".($count === 1 ? 'device' : 'devices').' signed out).';
    }
}
