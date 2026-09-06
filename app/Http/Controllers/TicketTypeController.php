<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company-dashboard controller for managing an Event's Ticket_Types.
 *
 * Every write action (create/update) is gated by the
 * `ACTION_MANAGE_TICKET_TYPES` authorisation Gate, which the role matrix grants
 * to the Admin role (and, via the `Gate::before` bypass, Super_Admins).
 * Anything else is denied with a 403 leaving data unchanged.
 * (Requirements 3.4, 3.7)
 *
 * Ticket_Types are nested under an Event (`/dashboard/events/{event}/ticket-types`).
 * Both the parent Event and its Ticket_Types are Company-owned: the dashboard
 * runs under the reserved `/dashboard` prefix where the `dashboard.tenant`
 * middleware binds the authenticated user's own Company onto the TenantContext,
 * so the global `company_id` scope constrains every Event/Ticket_Type query to
 * the user's Company. A request for another Company's Event (or Ticket_Type)
 * surfaces as 404 with no modification. (Requirements 1.5, 6.1)
 *
 * Money is submitted as a decimal amount (e.g. "12.50") and stored as integer
 * minor currency units (`price_minor`, e.g. 1250; 0 = free). (Requirements 6.1, 6.3)
 */
class TicketTypeController extends Controller
{
    /** Minimum number of Ticket_Types allowed per Event. (Requirement 6.2) */
    private const MIN_TYPES_PER_EVENT = 1;

    /** Maximum number of Ticket_Types allowed per Event. (Requirement 6.2) */
    private const MAX_TYPES_PER_EVENT = 50;

    /** Maximum price in minor units: 999,999.99 => 99,999,999. (Requirement 6.1) */
    private const MAX_PRICE_MINOR = 99_999_999;

    /** Maximum capacity. (Requirement 6.1) */
    private const MAX_CAPACITY = 1_000_000;

    /**
     * List the Event's Ticket_Types. Cross-Company Events never match the
     * tenant scope and surface as 404. (Requirements 1.5, 6.1)
     */
    public function index(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_TICKET_TYPES);

        $ticketTypes = $event->ticketTypes()->latest()->get();

        return view('dashboard.ticket-types.index', [
            'event' => $event,
            'ticketTypes' => $ticketTypes,
        ]);
    }

    /**
     * Create a Ticket_Type for the Event, scoped to the Admin's Company.
     * (Requirements 6.1, 6.2, 6.9)
     */
    public function store(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_TICKET_TYPES);

        $data = $this->validated($request, $event);

        // Enforce the 1–50 Ticket_Types-per-Event ceiling before creating.
        // (Requirement 6.2)
        if ($event->ticketTypes()->count() >= self::MAX_TYPES_PER_EVENT) {
            throw ValidationException::withMessages([
                'name' => __('An Event may have at most :max ticket types.', [
                    'max' => self::MAX_TYPES_PER_EVENT,
                ]),
            ]);
        }

        // company_id is auto-filled from the resolved tenant by the
        // BelongsToCompany trait; sold/reserved counts default to 0.
        $ticketType = $event->ticketTypes()->create($data);

        return redirect()
            ->route('dashboard.events.ticket-types.index', $event)
            ->with('status', 'Ticket type created.');
    }

    /**
     * Update a Ticket_Type and persist the updated details. Cross-Company rows
     * never match the tenant scope and surface as 404. (Requirements 6.1, 6.9)
     */
    public function update(Request $request, Event $event, TicketType $ticketType): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_TICKET_TYPES);

        $this->ensureBelongsToEvent($event, $ticketType);

        $ticketType->update($this->validated($request, $event));

        return redirect()
            ->route('dashboard.events.ticket-types.index', $event)
            ->with('status', 'Ticket type updated.');
    }

    /**
     * Validate Ticket_Type create/update input and map it to storable columns.
     *
     * Rules (Requirements 6.1, 6.9):
     *   - name:     1–100 characters
     *   - price:    decimal 0.00–999,999.99 (0 = free); stored as price_minor
     *   - capacity: integer 1–1,000,000
     *   - sale window: end strictly after start (else a sale-window-invalid
     *     error keyed on `sale_ends_at`)
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Event $event): array
    {
        $validated = $request->validate([
            // name 1–100 chars (required => at least 1 char). (Requirement 6.1)
            'name' => ['required', 'string', 'min:1', 'max:100'],
            // price 0.00–999,999.99 as a decimal; up to 2 decimal places.
            // (Requirements 6.1, 6.3)
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            // capacity 1–1,000,000. (Requirement 6.1)
            'capacity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CAPACITY],
            'sale_starts_at' => ['required', 'date'],
            // end strictly after start (sale-window-invalid). (Requirement 6.9)
            'sale_ends_at' => ['required', 'date', 'after:sale_starts_at'],
        ], [
            'sale_ends_at.after' => __('The sale window end must be strictly after the sale window start.'),
        ]);

        return [
            'name' => $validated['name'],
            // Convert the decimal price to integer minor units, e.g. "12.50" =>
            // 1250. round() guards against binary float artefacts on multiply.
            'price_minor' => (int) round(((float) $validated['price']) * 100),
            'capacity' => (int) $validated['capacity'],
            'sale_starts_at' => $validated['sale_starts_at'],
            'sale_ends_at' => $validated['sale_ends_at'],
        ];
    }

    /**
     * Guard that the resolved Ticket_Type belongs to the resolved Event. Both
     * are already tenant-scoped, but a Ticket_Type of another Event within the
     * same Company must not be reachable via this Event's nested route.
     */
    private function ensureBelongsToEvent(Event $event, TicketType $ticketType): void
    {
        abort_unless($ticketType->event_id === $event->id, 404);
    }
}
