<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
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
}
