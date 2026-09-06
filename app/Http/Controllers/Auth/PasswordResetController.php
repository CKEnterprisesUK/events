<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Self-service password reset ("forgot password") for Company_Users, built on
 * Laravel's password broker and the `password_reset_tokens` table.
 *
 * Four steps, all on the `guest` surface (a signed-in user does not reset via
 * email): request the reset link, email a signed token, show the reset form for
 * a valid token, and set the new password. To avoid leaking which emails have
 * accounts, the request step always reports the same neutral confirmation
 * regardless of whether the email matched.
 */
class PasswordResetController extends Controller
{
    /**
     * Show the "forgot your password?" email-entry form.
     */
    public function requestForm(): View
    {
        return view('auth.passwords.email');
    }

    /**
     * Email a password-reset link for the given address. Always responds with
     * the same neutral status so the form does not reveal whether an account
     * exists for the email.
     */
    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'If that email is registered, a reset link is on its way.');
    }

    /**
     * Show the reset form for a token emailed to the user. The email is
     * carried through from the query string so it can be re-submitted with the
     * token.
     */
    public function resetForm(Request $request, string $token): View
    {
        return view('auth.passwords.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * Set the new password for a valid token, then redirect to login. An
     * invalid or expired token fails validation with a message rather than
     * changing anything.
     */
    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')
                ->with('status', 'Your password has been reset. Please log in.');
        }

        return back()->withErrors(['email' => __($status)]);
    }
}
