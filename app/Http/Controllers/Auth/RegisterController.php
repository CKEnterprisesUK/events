<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Rules\CompanySlug;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Handles public self-signup.
 *
 * A signup creates a brand-new Company (tenant) together with its single Owner
 * user, then establishes a session for that Owner. The Owner is the highest
 * Company role and can invite Admins/Accountants/Scanners from the dashboard.
 *
 * Note: this is deliberately the only self-service account creation path — all
 * other Company_Users join by invitation (see InvitationController). Platform
 * Super_Admins are never created here; that flag is set out-of-band in the DB.
 */
class RegisterController extends Controller
{
    /**
     * Show the signup form.
     */
    public function show(): View
    {
        return view('auth.register');
    }

    /**
     * Create a Company + its Owner user, then log the Owner in.
     */
    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', new CompanySlug],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $company = Company::create([
                'name' => $validated['company_name'],
                'slug' => $validated['slug'],
            ]);

            return User::create([
                'company_id' => $company->id,
                'role' => User::ROLE_OWNER,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);
        });

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}
