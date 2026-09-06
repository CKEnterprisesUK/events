<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Handles Company_User authentication: showing the login form, establishing a
 * session on valid credentials, and logging out.
 *
 * Authentication uses Laravel's session-based `web` guard against the `users`
 * table. Role scoping, tenant scoping, and the single-Owner invariant are added
 * in later tasks (2.x/4.x); this task provides the login/logout/session base.
 *
 * Requirement 3.9 (invalid credentials rejected) is honoured here by returning
 * an invalid-credentials error and granting no session.
 */
class LoginController extends Controller
{
    /**
     * Show the login form.
     */
    public function show(): View
    {
        return view('auth.login');
    }

    /**
     * Attempt to authenticate a Company_User and establish a session.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // A Company_User of a suspended Company must not be granted a session,
        // even with valid credentials. Tear the session down and reject the
        // login with an error. (Requirement 2.3)
        $company = Auth::user()->company;

        if ($company instanceof Company && $company->isSuspended()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => __('This account has been suspended.'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    /**
     * Log the Company_User out and invalidate the session.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
