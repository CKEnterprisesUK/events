<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Event;
use App\Services\Branding\BrandingResolver;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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

    public function __construct(private readonly BrandingResolver $resolver) {}

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
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:5120'],
            'primary_colour' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'terms_text' => ['nullable', 'string', 'max:20000'],
            'support_email' => ['nullable', 'string', 'email', 'max:254'],
            'gdpr_contact_email' => ['nullable', 'string', 'email', 'max:254'],
            'ticket_field_defs' => ['nullable', 'array'],
            'ticket_field_defs.*' => ['nullable', 'string', 'max:100'],
        ]);

        $attributes = [
            'primary_colour' => $data['primary_colour'] ?? null,
            'terms_text' => $data['terms_text'] ?? null,
            'support_email' => $data['support_email'] ?? null,
            'gdpr_contact_email' => $data['gdpr_contact_email'] ?? null,
            'ticket_field_defs' => $this->normaliseFieldDefs($data['ticket_field_defs'] ?? null),
        ];

        if ($request->hasFile('logo')) {
            $attributes['logo_path'] = $this->storeLogo($request, $company->logo_path);
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
        ]);
    }

    /**
     * Persist Event-level branding overrides (logo, primary colour, custom
     * ticket-info fields) for one Event. Blank values clear the override so the
     * Event falls back to the Company-level branding. (Requirement 7.5)
     */
    public function updateEvent(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $data = $request->validate([
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:5120'],
            'primary_colour' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'ticket_field_defs' => ['nullable', 'array'],
            'ticket_field_defs.*' => ['nullable', 'string', 'max:100'],
        ]);

        $attributes = [
            'primary_colour' => $data['primary_colour'] ?? null,
            'ticket_field_defs' => $this->normaliseFieldDefs($data['ticket_field_defs'] ?? null),
        ];

        if ($request->hasFile('logo')) {
            $attributes['logo_path'] = $this->storeLogo($request, $event->logo_path);
        }

        $event->update($attributes);

        return redirect()
            ->route('dashboard.branding.event.edit', $event)
            ->with('status', 'Event branding updated.');
    }

    // ---- Helpers -------------------------------------------------------------

    /**
     * Store the uploaded logo on the `public` disk and return its relative path
     * for persistence in `logo_path`. Any previously stored logo on the same
     * disk is removed so old files do not accumulate. (Requirement 7.1)
     */
    private function storeLogo(Request $request, ?string $previousPath): string
    {
        $path = $request->file('logo')->store(self::LOGO_DIRECTORY, 'public');

        if ($previousPath !== null && $previousPath !== '' && $previousPath !== $path) {
            Storage::disk('public')->delete($previousPath);
        }

        return $path;
    }

    /**
     * Normalise submitted custom ticket-info field definitions into the stored
     * shape: a list of `['label' => ...]` entries, dropping blanks. Storing a
     * structured shape keeps ticket rendering stable as fields grow richer.
     * (Requirement 7.4)
     *
     * @param  array<int|string, mixed>|null  $fields
     * @return list<array<string, string>>
     */
    private function normaliseFieldDefs(?array $fields): array
    {
        if ($fields === null) {
            return [];
        }

        $defs = [];

        foreach ($fields as $field) {
            if (! is_string($field)) {
                continue;
            }

            $label = trim($field);

            if ($label !== '') {
                $defs[] = ['label' => $label];
            }
        }

        return $defs;
    }

    /**
     * The authenticated Company_User's own Company. An authenticated
     * Company_User is always scoped to their own Company; a user without one
     * (should not reach this Owner-gated surface) yields a 404 rather than
     * acting on nothing.
     */
    private function currentCompany(): Company
    {
        $company = Auth::user()?->company;

        if (! $company instanceof Company) {
            throw new NotFoundHttpException;
        }

        return $company;
    }
}
