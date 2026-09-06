<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Self-service account profile for the authenticated Company_User: view and
 * update their own name and email, and change their password.
 *
 * This is deliberately open to every authenticated Company_User (any role) —
 * it only ever acts on the acting user's OWN record, so no Company role matrix
 * gate applies. It sits inside the authenticated dashboard group but does not
 * need the tenant scope, since a user always edits themselves.
 */
class ProfileController extends Controller
{
    /**
     * Show the profile form pre-filled with the user's current details.
     */
    public function edit(Request $request): View
    {
        return view('dashboard.profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's name and email. Email must stay unique across users.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:254',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $user->update($data);

        return redirect()
            ->route('dashboard.profile.edit')
            ->with('status', 'Profile updated.');
    }

    /**
     * Change the user's password. The current password must be supplied and
     * correct, and the new password confirmed. On success every other session
     * for this user is invalidated so a leaked session cannot outlive the
     * change.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user->update([
            'password' => Hash::make($data['password']),
        ]);

        // Keep this session alive but log out all others.
        Auth::logoutOtherDevices($data['password']);

        return redirect()
            ->route('dashboard.profile.edit')
            ->with('password_status', 'Password changed.');
    }

    /**
     * Sign the user out of every OTHER session, keeping the current one alive.
     * Requires the current password (Laravel's `logoutOtherDevices` re-hashes
     * it into the remaining session) so a hijacked, password-less session
     * cannot evict the legitimate user. Useful after losing a device or
     * suspecting a session was left open elsewhere.
     */
    public function logoutOtherSessions(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ]);

        Auth::logoutOtherDevices($data['current_password']);

        return redirect()
            ->route('dashboard.profile.edit')
            ->with('sessions_status', 'Signed out of all other sessions.');
    }

    /**
     * Download the personal data held on the acting user's OWN account record
     * as a JSON file. This is the user-facing counterpart to the per-Customer
     * GDPR export: it only ever exposes the requester's own account fields
     * (never another user's, never customer data), so no role gate applies —
     * the same reasoning that keeps the rest of this controller open to any
     * authenticated Company_User. Secret/credential fields (password hash,
     * remember token) are deliberately excluded.
     */
    public function downloadData(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'company' => $user->company?->name,
                'agreed_to_terms_at' => optional($user->agreed_to_terms_at)->toIso8601String(),
                'last_activity_at' => optional($user->last_activity_at)->toIso8601String(),
                'created_at' => optional($user->created_at)->toIso8601String(),
            ],
        ];

        return response()
            ->json($data, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ->header('Content-Disposition', 'attachment; filename="my-account-data.json"');
    }
}
