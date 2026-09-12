<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Event;
use App\Rules\SafeUpload;
use App\Services\Branding\BrandingResolver;
use App\Services\BrandingImageStore;
use App\Services\EventReadiness;
use App\Services\RoleAuthorization;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Company-dashboard controller for branding and ticket customisation.
 *
 * Two surfaces, gated differently by the role matrix:
 *   - Company-level branding (logo, primary colour, Terms & Conditions text, and
 *     custom ticket-info field definitions) is a Company *setting*, so it is
 *     gated on the Owner-only `ACTION_MANAGE_SETTINGS`. (Requirements 7.1–7.4)
 *   - Event-level branding overrides (logo, primary colour, ticket-info fields
 *     for one Event) are part of managing that Event, so they are gated on the
 *     Admin `ACTION_MANAGE_EVENTS`. (Requirement 7.5)
 *
 * The upload is stored on the `public` filesystem disk and the stored relative
 * path is persisted in `logo_path`; the storefront/ticket surfaces resolve the
 * public URL from it. (`storage:link` is wired by the hosting task; here we only
 * store to the disk.) The effective branding used by the Storefront, checkout,
 * and ticket rendering is produced by {@see BrandingResolver}, which layers
 * Event overrides over Company defaults. (Requirement 7.5)
 *
 * Branding is Company-owned: the dashboard runs under the reserved `/dashboard`
 * prefix, so `dashboard.tenant` binds the authenticated user's own Company onto
 * the TenantContext and the global `company_id` scope constrains every Event
 * query — an Admin only ever overrides Events for their own Company, and a
 * foreign Event id surfaces as 404. (Requirements 1.5, 5.1)
 */
class BrandingController extends Controller
{
    /**
     * Where uploaded logos live on the `public` disk.
     */
    private const LOGO_DIRECTORY = 'branding/logos';

    /**
     * Where uploaded posters/hero images live on the `public` disk.
     */
    private const POSTER_DIRECTORY = 'branding/posters';

    public function __construct(
        private readonly BrandingResolver $resolver,
        private readonly BrandingImageStore $imageStore,
        private readonly EventReadiness $readiness,
        private readonly TenantContext $tenantContext,
    ) {}

    // ---- Company-level branding (Owner, ACTION_MANAGE_SETTINGS) --------------

    /**
     * Show the Company branding settings form with the current effective
     * Company branding. (Requirements 7.1–7.4)
     */
    public function edit(): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_SETTINGS);

        $company = $this->currentCompany();

        return view('dashboard.branding.edit', [
            'company' => $company,
            'branding' => $this->resolver->forCompany($company),
        ]);
    }

    /**
     * Persist Company-level branding: store an uploaded logo, set the primary
     * brand colour, set the Terms & Conditions text, and define the custom
     * ticket information fields. (Requirements 7.1, 7.2, 7.3, 7.4)
     */
    public function update(Request $request): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_SETTINGS);

        $company = $this->currentCompany();

        $data = $request->validate([
            // SVG is intentionally excluded (stored-XSS risk) and the shared
            // SafeUpload denylist backstops the allow-list. (Security hardening)
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120', new SafeUpload],
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:8192', new SafeUpload],
            'primary_colour' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'terms_text' => ['nullable', 'string', 'max:20000'],
            'privacy_text' => ['nullable', 'string', 'max:20000'],
            'support_email' => ['nullable', 'string', 'email', 'max:254'],
            'gdpr_contact_email' => ['nullable', 'string', 'email', 'max:254'],

            // Public storefront profile: about-the-company blurb and external
            // links (social profiles + the organiser's own legal pages).
            'about_text' => ['nullable', 'string', 'max:5000'],
            'facebook_url' => ['nullable', 'string', 'url', 'max:255'],
            'instagram_url' => ['nullable', 'string', 'url', 'max:255'],
            'x_url' => ['nullable', 'string', 'url', 'max:255'],
            'linkedin_url' => ['nullable', 'string', 'url', 'max:255'],

            // Legal/registration details maintained by the Owner after signup.
            // The registered name, organisation type, main organisation email
            // and registered address stay required; the rest are optional.
            'legal_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'organisation_type' => ['required', Rule::in(array_keys(Company::ORGANISATION_TYPES))],
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
            // Optional even for charities: small charities under the
            // registration threshold, and excepted/exempt charities, have no
            // Charity Commission number.
            'charity_number' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'url', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
        ]);

        $attributes = [
            'primary_colour' => $data['primary_colour'] ?? null,
            'terms_text' => $data['terms_text'] ?? null,
            'privacy_text' => $data['privacy_text'] ?? null,
            'support_email' => $data['support_email'] ?? null,
            'gdpr_contact_email' => $data['gdpr_contact_email'] ?? null,
            'about_text' => $data['about_text'] ?? null,
            'facebook_url' => $data['facebook_url'] ?? null,
            'instagram_url' => $data['instagram_url'] ?? null,
            'x_url' => $data['x_url'] ?? null,
            'linkedin_url' => $data['linkedin_url'] ?? null,
            'legal_name' => $data['legal_name'],
            'trading_name' => $data['trading_name'] ?? null,
            'organisation_type' => $data['organisation_type'],
            'company_number' => $data['company_number'] ?? null,
            'charity_number' => $data['charity_number'] ?? null,
            'website' => $data['website'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'address_line_1' => $data['address_line_1'],
            'address_line_2' => $data['address_line_2'] ?? null,
            'city' => $data['city'],
            'postcode' => $data['postcode'],
            'country' => strtoupper($data['country']),
        ];

        if ($request->hasFile('logo')) {
            $attributes['logo_path'] = $this->imageStore->store($request->file('logo'), self::LOGO_DIRECTORY, $company->logo_path);
        }

        if ($request->hasFile('poster')) {
            $attributes['poster_path'] = $this->imageStore->store($request->file('poster'), self::POSTER_DIRECTORY, $company->poster_path);
        }

        $company->update($attributes);

        return redirect()
            ->route('dashboard.branding.edit')
            ->with('status', 'Branding updated.');
    }

    // ---- Event-level branding overrides (Admin, ACTION_MANAGE_EVENTS) --------

    /**
     * Show the Event branding-override form, alongside the effective branding
     * the Event resolves to (Event override else Company fallback). The Event is
     * scoped to the Company by the tenant scope; a foreign id 404s. (7.5)
     */
    public function editEvent(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        return view('dashboard.branding.event', [
            'event' => $event,
            'branding' => $this->resolver->forEvent($event),
            // The branding screen renders inside the manage-event layout, which
            // needs the readiness checklist for the shared section nav sidebar.
            'readiness' => $this->readiness->checklist($event),
        ]);
    }

    /**
     * Persist Event-level branding overrides (logo, poster, primary colour) for
     * one Event, plus the custom entry instructions printed on the ticket. Blank
     * override fields clear the override so the Event falls back to the
     * Company-level branding. Sponsors are managed on their own screen.
     * (Requirement 7.5)
     */
    public function updateEvent(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $data = $request->validate([
            // SVG excluded here too; SafeUpload denylist backstops every slot.
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120', new SafeUpload],
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:8192', new SafeUpload],
            'primary_colour' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],

            // Per-event printed-ticket design. Sponsors are managed on their own
            // screen (dashboard.events.sponsors), no longer here.
            'ticket_instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = [
            'primary_colour' => $data['primary_colour'] ?? null,
            'ticket_instructions' => $data['ticket_instructions'] ?? null,
        ];

        if ($request->hasFile('logo')) {
            $attributes['logo_path'] = $this->imageStore->store($request->file('logo'), self::LOGO_DIRECTORY, $event->logo_path);
        }

        if ($request->hasFile('poster')) {
            $attributes['poster_path'] = $this->imageStore->store($request->file('poster'), self::POSTER_DIRECTORY, $event->poster_path);
        }

        $event->update($attributes);

        return redirect()
            ->route('dashboard.branding.event.edit', $event)
            ->with('status', 'Event branding updated.');
    }

    // ---- Helpers -------------------------------------------------------------

    /**
     * The Company currently being acted on.
     *
     * This is the tenant bound onto {@see TenantContext} by `dashboard.tenant`,
     * which is the authenticated Company_User's own Company OR — for a
     * Super_Admin who has jumped into a tenant — the impersonated Company. Using
     * the resolved tenant (rather than `Auth::user()->company`) ensures an
     * impersonating Super_Admin edits the impersonated Company's settings, not
     * their own. Falls back to the user's own Company for safety, and a request
     * with no acting Company (should not reach this Owner-gated surface) yields
     * a 404 rather than acting on nothing.
     */
    private function currentCompany(): Company
    {
        $company = $this->tenantContext->company() ?? Auth::user()?->company;

        if (! $company instanceof Company) {
            throw new NotFoundHttpException;
        }

        return $company;
    }
}
