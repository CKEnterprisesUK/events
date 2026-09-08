<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\AuditLogger;
use App\Services\RoleAuthorization;
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

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Ticket-type management now lives on the dedicated "Tickets" manage screen
     * (dashboard.events.tickets). This route is kept registered so existing
     * bookmarks/links and the store/update redirects keep working; it simply
     * forwards to that screen. Cross-Company Events never match the tenant scope
     * and surface as 404. (Requirements 1.5, 6.1, 6.3)
     */
    public function index(Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_TICKET_TYPES);

        return redirect()->route('dashboard.events.tickets', $event);
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

        $this->audit->record(
            action: AuditLog::TICKET_TYPE_CREATED,
            auditable: $ticketType,
            summary: 'Created ticket type "'.$ticketType->name.'" for '.$event->name,
            context: ['event_id' => (int) $event->getKey()],
        );

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

        $this->audit->record(
            action: AuditLog::TICKET_TYPE_UPDATED,
            auditable: $ticketType,
            summary: 'Updated ticket type "'.$ticketType->name.'" for '.$event->name,
            context: ['event_id' => (int) $event->getKey()],
        );

        return redirect()
            ->route('dashboard.events.ticket-types.index', $event)
            ->with('status', 'Ticket type updated.');
    }

    /**
     * Delete a Ticket_Type from the Event, scoped to the Admin's Company.
     *
     * A published Event must always keep at least one Ticket_Type — that is the
     * "≥1 ticket type" precondition for publishing (Requirement 6.2). So when
     * the Event is published and this is its last remaining Ticket_Type, the
     * delete is refused with an error and no modification is made; the publish
     * invariant can never be broken from the accordion. A draft Event may be
     * emptied freely. Cross-Company rows never match the tenant scope and
     * surface as 404. (Requirements 6.1, 6.2)
     */
    public function destroy(Event $event, TicketType $ticketType): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_TICKET_TYPES);

        $this->ensureBelongsToEvent($event, $ticketType);

        // Refuse to drop the last Ticket_Type of a published Event: doing so
        // would violate publishing's "≥1 ticket type" blocker. (Requirement 6.2)
        if ($event->isPublished()
            && $event->ticketTypes()->count() <= self::MIN_TYPES_PER_EVENT) {
            return redirect()
                ->route('dashboard.events.ticket-types.index', $event)
                ->with('error', __('A published event must keep at least one ticket type. Unpublish the event first, or add another ticket type before removing this one.'));
        }

        $name = $ticketType->name;
        $ticketType->delete();

        $this->audit->record(
            action: AuditLog::TICKET_TYPE_DELETED,
            auditable: $ticketType,
            summary: 'Deleted ticket type "'.$name.'" for '.$event->name,
            context: ['event_id' => (int) $event->getKey()],
        );

        return redirect()
            ->route('dashboard.events.ticket-types.index', $event)
            ->with('status', 'Ticket type deleted.');
    }

    /**
     * Validate Ticket_Type create/update input and map it to storable columns.
     *
     * Rules (Requirements 6.1, 6.9, 7.2, 7.3, 7.4, 8.3, 8.4, 8.9, 9.1):
     *   - name:        1–100 characters
     *   - description: optional, up to 1,000 characters (Requirement 9.1)
     *   - price:       decimal 0.00–999,999.99 (0 = free); stored as price_minor
     *   - capacity_mode: one of {@see TicketType::MODES} (capped | shared_pool |
     *     unlimited)
     *   - capacity:    integer 1–1,000,000, required only for `capped`; nulled
     *     for `shared_pool`/`unlimited` (Requirements 7.2, 7.3, 7.4)
     *   - sale window: both bounds are nullable. The accordion's "use default"
     *     checkboxes `disable` the datetime inputs, so an unchecked default
     *     leaves the datetime absent from the request and it persists as `null`
     *     (Requirements 8.3, 8.4, 8.9). The "end strictly after start" and "end
     *     before_or_equal event start" checks apply only when the explicit
     *     datetimes are present.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Event $event): array
    {
        $validated = $request->validate([
            // name 1–100 chars (required => at least 1 char). (Requirement 6.1)
            'name' => ['required', 'string', 'min:1', 'max:100'],
            // optional free-text description, up to 1,000 chars. (Requirement 9.1)
            'description' => ['nullable', 'string', 'max:1000'],
            // price 0.00–999,999.99 as a decimal; up to 2 decimal places.
            // (Requirements 6.1, 6.3)
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            // capacity mode: capped (per-type ceiling), shared_pool (draws only
            // from the event overall capacity), or unlimited (never finitely
            // bound). (Requirements 7.2, 7.3, 7.4)
            'capacity_mode' => ['required', Rule::in(TicketType::MODES)],
            // capacity required 1–1,000,000 for capped only; nullable for
            // shared_pool/unlimited (ignored server-side). (Requirements 7.2, 7.3, 7.4)
            'capacity' => [
                Rule::requiredIf(fn () => $request->input('capacity_mode') === TicketType::MODE_CAPPED),
                'nullable', 'integer', 'min:1', 'max:'.self::MAX_CAPACITY,
            ],
            // Both sale bounds are nullable: an absent input (its "use default"
            // checkbox left checked, disabling the input) persists as null.
            // (Requirements 8.3, 8.4, 8.9)
            'sale_starts_at' => ['nullable', 'date'],
            // end strictly after start (sale-window-invalid) only when start is
            // present; and when the Event has a start time, sales must also
            // close by then — selling a ticket for a session that has already
            // begun makes no sense, so the window end is capped at the Event
            // start. Both checks apply only when the explicit datetimes are
            // present. (Requirements 6.9, 8.9)
            'sale_ends_at' => array_filter([
                'nullable', 'date',
                $request->filled('sale_starts_at') ? 'after:sale_starts_at' : null,
                $event->starts_at ? 'before_or_equal:'.$event->starts_at->format('Y-m-d\TH:i:s') : null,
            ]),
        ], [
            'sale_ends_at.after' => __('The sale window end must be strictly after the sale window start.'),
            'sale_ends_at.before_or_equal' => __('Ticket sales must end by the time the event starts.'),
        ]);

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            // Convert the decimal price to integer minor units, e.g. "12.50" =>
            // 1250. round() guards against binary float artefacts on multiply.
            'price_minor' => (int) round(((float) $validated['price']) * 100),
            'capacity_mode' => $validated['capacity_mode'],
            // Only capped types carry a per-type capacity; shared_pool and
            // unlimited types have no per-type ceiling. (Requirements 7.2, 7.3, 7.4)
            'capacity' => $validated['capacity_mode'] === TicketType::MODE_CAPPED
                ? (int) $validated['capacity']
                : null,
            // Absent datetimes (default checkbox left checked) persist as null.
            // (Requirements 8.3, 8.4, 8.9)
            'sale_starts_at' => $validated['sale_starts_at'] ?? null,
            'sale_ends_at' => $validated['sale_ends_at'] ?? null,
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
