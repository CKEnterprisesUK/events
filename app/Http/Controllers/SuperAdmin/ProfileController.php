<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Self-service account + security page for a CK Enterprises Super_Admin, on the
 * platform surface (`/admin/profile`). The Super_Admin counterpart to
 * {@see \App\Http\Controllers\ProfileController} — it acts only on the acting
 * Super_Admin's OWN record.
 *
 * A Super_Admin has no Company (name/email are provisioned out-of-band), so
 * this page deliberately does NOT offer email changes; it provides the two
 * account-security controls that matter for the highest-privilege account:
 * changing the password, and managing two-factor authentication (the MFA
 * enable/disable/confirm actions live on {@see TwoFactorController}). The whole
 * `/admin` group is already gated to Super_Admins by the `super.admin`
 * middleware, so — matching the SuperAdmin controller convention — there are no
 * per-action Gate calls here.
 */
class ProfileController extends Controller
{
    /**
     * Show the security page: MFA status/controls + change-password form.
     */
    public function edit(Request $request): View
    {
        return view('admin.profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Change the Super_Admin's password. The current password must be supplied
     * and correct, and the new password confirmed. On success every OTHER
     * session for this account is invalidated so a leaked session cannot
     * outlive the change.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update([
            'password' => Hash::make($data['password']),
        ]);

        // Keep this session alive but log out all others.
        Auth::logoutOtherDevices($data['password']);

        return redirect()
            ->route('admin.profile.edit')
            ->with('password_status', 'Password changed.');
    }
}
