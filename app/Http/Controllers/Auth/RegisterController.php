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
use Illuminate\Validation\Rule;
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

            // Legal/registration details required by the legal team. The
            // registered name, organisation type, main organisation email, and
            // registered address are mandatory; trading name, website, phone and
            // the second address line are optional.
            'legal_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'organisation_type' => ['required', Rule::in(array_keys(Company::ORGANISATION_TYPES))],
            // Companies House number is expected for companies and CICs; the
            // Charity Commission number for charities. Both are optional
            // otherwise, and never both required at once.
            'company_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::requiredIf(fn (): bool => in_array(
                    $request->input('organisation_type'),
                    [Company::TYPE_COMPANY, Company::TYPE_CIC],
                    true,
                )),
            ],
            'charity_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::requiredIf(fn (): bool => $request->input('organisation_type') === Company::TYPE_CHARITY),
            ],
            'website' => ['nullable', 'string', 'url', 'max:255'],
            'organisation_email' => ['required', 'string', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],

            // The Owner account.
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $company = Company::create([
                'name' => $validated['company_name'],
                'slug' => $validated['slug'],
                'legal_name' => $validated['legal_name'],
                'trading_name' => $validated['trading_name'] ?? null,
                'organisation_type' => $validated['organisation_type'],
                'company_number' => $validated['company_number'] ?? null,
                'charity_number' => $validated['charity_number'] ?? null,
                'website' => $validated['website'] ?? null,
                'email' => $validated['organisation_email'],
                'phone' => $validated['phone'] ?? null,
                'address_line_1' => $validated['address_line_1'],
                'address_line_2' => $validated['address_line_2'] ?? null,
                'city' => $validated['city'],
                'postcode' => $validated['postcode'],
                'country' => strtoupper($validated['country']),
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
