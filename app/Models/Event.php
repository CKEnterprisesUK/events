<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An Event is a scheduled occurrence for which a Company sells tickets,
 * addressed publicly at `events.domain/{company-slug}/{event-id}/`.
 *
 * Events are Company-owned: the {@see BelongsToCompany} trait registers the
 * global `company_id` tenant scope and auto-fills `company_id` from the
 * resolved tenant on create, so an Event always belongs to the active Company
 * and cross-Company rows never match. (Requirement 5.1)
 *
 * `capacity` is the optional overall Event capacity (NULL = unlimited).
 * `is_published` gates public availability: a published Event's page is served
 * to Customers (Requirement 5.4) while an unpublished Event is not viewable or
 * purchasable (Requirement 5.5). The branding columns are per-Event overrides
 * of the Company-level branding. (Requirement 7.5)
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $description
 * @property string|null $venue
 * @property Carbon|null $starts_at
 * @property int|null $capacity
 * @property bool $is_published
 * @property string $location_mode
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $primary_colour
 * @property string|null $logo_path
 * @property string|null $poster_path
 * @property string|null $ticket_instructions
 * @property string|null $sponsor_top_path
 * @property string|null $sponsor_top_name
 * @property string|null $sponsor_top_website
 * @property string|null $sponsor_top_bio
 * @property bool $sponsor_top_on_ticket
 * @property string|null $sponsor_bottom_path
 * @property string|null $sponsor_bottom_name
 * @property string|null $sponsor_bottom_website
 * @property string|null $sponsor_bottom_bio
 * @property bool $sponsor_bottom_on_ticket
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use BelongsToCompany, HasFactory;

    public const LOCATION_IN_PERSON = 'in_person';

    public const LOCATION_ONLINE = 'online';

    /** @var list<string> */
    public const LOCATION_MODES = [self::LOCATION_IN_PERSON, self::LOCATION_ONLINE];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'description',
        'venue',
        'starts_at',
        'capacity',
        'is_published',
        'location_mode',
        'address',
        'latitude',
        'longitude',
        'primary_colour',
        'logo_path',
        'poster_path',
        'ticket_instructions',
        'sponsor_top_path',
        'sponsor_top_name',
        'sponsor_top_website',
        'sponsor_top_bio',
        'sponsor_top_on_ticket',
        'sponsor_bottom_path',
        'sponsor_bottom_name',
        'sponsor_bottom_website',
        'sponsor_bottom_bio',
        'sponsor_bottom_on_ticket',
        'cancelled_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_published' => false,
        'location_mode' => self::LOCATION_IN_PERSON,
        'sponsor_top_on_ticket' => true,
        'sponsor_bottom_on_ticket' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'capacity' => 'integer',
            'is_published' => 'boolean',
            'sponsor_top_on_ticket' => 'boolean',
            'sponsor_bottom_on_ticket' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * The Ticket_Types offered for this Event. (Requirement 6.2)
     *
     * @return HasMany<TicketType, $this>
     */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    /**
     * The Orders placed against this Event.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The custom questions asked of the Customer at checkout for this Event,
     * in display order (at most {@see EventQuestion::MAX_PER_EVENT}).
     *
     * @return HasMany<EventQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(EventQuestion::class)->orderBy('position');
    }

    /**
     * The sponsors shown for this Event, in display order. A sponsor carries a
     * logo plus optional store-page details and an `on_ticket` flag. (Sponsors
     * management)
     *
     * @return HasMany<EventSponsor, $this>
     */
    public function sponsors(): HasMany
    {
        return $this->hasMany(EventSponsor::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Whether this Event is published and therefore available to Customers at
     * its public page. (Requirements 5.4, 5.5)
     */
    public function isPublished(): bool
    {
        return (bool) $this->is_published;
    }

    /**
     * Whether this Event is held at a physical venue. (Requirement 4.1)
     */
    public function isInPerson(): bool
    {
        return $this->location_mode === self::LOCATION_IN_PERSON;
    }

    /**
     * Whether this Event is held online. (Requirement 4.1)
     */
    public function isOnline(): bool
    {
        return $this->location_mode === self::LOCATION_ONLINE;
    }

    /**
     * Whether this Event has a resolved map pin. (Requirement 4.6)
     */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Overall remaining capacity = capacity - sum(sold_count + reserved_count)
     * across all ticket types; null when the Event has no overall capacity
     * (unlimited). (Requirements 2.4, 2.6)
     */
    public function overallRemaining(): ?int
    {
        if ($this->capacity === null) {
            return null; // unlimited
        }

        $committed = (int) $this->ticketTypes()->sum(DB::raw('sold_count + reserved_count'));

        return $this->capacity - $committed;
    }

    /**
     * A sell-through summary for this Event: how many tickets are committed
     * (sold) against the Event's total capacity, plus the percentage full.
     *
     * Capacity resolution:
     *   - if the Event has an overall `capacity`, that is the ceiling;
     *   - otherwise the ceiling is the sum of the per-type capacities of the
     *     Event's capped ticket types;
     *   - if neither resolves to a positive ceiling (unlimited, or only
     *     shared-pool types with no overall cap), capacity is null (unlimited)
     *     and no meaningful percentage exists.
     *
     * "Sold" is the sum of `sold_count` across the Event's ticket types — the
     * confirmed committed quantity the CapacityReservationService maintains,
     * which is the authoritative sold figure (reserved holds are excluded).
     *
     * Pass a precomputed `[event_id => sold, event_id => capped_capacity]` pair
     * to avoid per-row queries when rendering a list; otherwise the figures are
     * queried from the Event's ticket types.
     *
     * @return array{sold: int, capacity: int|null, percent: float|null}
     */
    public function sellThrough(?int $sold = null, ?int $cappedCapacity = null): array
    {
        if ($sold === null || $cappedCapacity === null) {
            $sold = (int) $this->ticketTypes()->sum('sold_count');
            $cappedCapacity = (int) $this->ticketTypes()
                ->where('capacity_mode', TicketType::MODE_CAPPED)
                ->sum('capacity');
        }

        // Overall Event capacity wins as the ceiling; else the summed capped
        // per-type capacities. A non-positive result means "unlimited".
        $capacity = $this->capacity ?? ($cappedCapacity > 0 ? $cappedCapacity : null);
        if ($capacity !== null && $capacity <= 0) {
            $capacity = null;
        }

        $percent = $capacity !== null && $capacity > 0
            ? round(min(100, ($sold / $capacity) * 100), 1)
            : null;

        return [
            'sold' => $sold,
            'capacity' => $capacity,
            'percent' => $percent,
        ];
    }

    /**
     * The unmet publish prerequisites for this Event, as an ordered map of
     * blocker key => human message. An empty array means the Event is
     * publishable. This is the single source of truth reused by the controller
     * (enforcement) and the Manage_Event_Page (presentation). (Requirements 1.1, 1.2)
     *
     * @return array<string, string>
     */
    public function publishBlockers(): array
    {
        $blockers = [];

        if ($this->starts_at === null) {
            $blockers['starts_at'] = 'Set a start date and time.';
        }

        // ticketTypes()->exists() is tenant-scoped like the Event itself.
        if (! $this->ticketTypes()->exists()) {
            $blockers['ticket_types'] = 'Add at least one ticket type.';
        }

        // A shared-pool type draws only from the overall capacity; without one
        // its capacity is undefined, so the Event cannot go live. (Requirements 3.1, 3.4)
        if ($this->capacity === null
            && $this->ticketTypes()->where('capacity_mode', TicketType::MODE_SHARED_POOL)->exists()) {
            $blockers['shared_pool_capacity'] =
                'Set an overall event capacity: a shared-pool ticket type needs an overall ceiling to draw from.';
        }

        // Payment readiness: if this Event sells any paid ticket type, the
        // Company must be able to take card payments before it goes live —
        // otherwise Customers would see paid tickets they cannot actually buy
        // (checkout refuses payment when the account is not charges-enabled).
        // Free-only Events (every type priced 0) never need Stripe. The Company
        // is the single source of truth for payment readiness. (Requirements
        // 11.1, 11.5, 12.1)
        if ($this->ticketTypes()->where('price_minor', '>', 0)->exists()
            && ! ($this->company?->canAcceptPayments() ?? false)) {
            $blockers['payments'] =
                'Connect Stripe and enable charges before publishing: this event sells paid tickets.';
        }

        return $blockers;
    }

    /**
     * Whether this Event has no unmet publish prerequisites. (Requirements 1.1, 1.2)
     */
    public function isPublishable(): bool
    {
        return $this->publishBlockers() === [];
    }

    /**
     * Publish the Event, making its page available to Customers. (5.4)
     */
    public function publish(): bool
    {
        $this->is_published = true;

        return $this->save();
    }

    /**
     * Unpublish the Event, blocking Customer view/purchase. (5.5)
     */
    public function unpublish(): bool
    {
        $this->is_published = false;

        return $this->save();
    }

    /**
     * Whether this Event has any confirmed bookings — Orders that were paid or
     * free-confirmed (and so had tickets issued). This is the guard that
     * decides between the two organiser actions: an Event with no bookings can
     * be deleted outright, while an Event that has taken bookings can only be
     * cancelled (its customers must be contacted and any refunds arranged with
     * support). Reserved/expired/voided Orders never issued tickets, so they do
     * not count. (Deletion vs. cancellation rule.)
     */
    public function hasBookings(): bool
    {
        return $this->orders()
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED])
            ->exists();
    }

    /**
     * Whether this Event has been cancelled. A cancelled Event is retained (its
     * booking records must survive for refunds/customer contact) but is
     * unpublished and no longer sells tickets. (Deletion vs. cancellation rule.)
     */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Cancel the Event: stamp `cancelled_at` and unpublish it so it drops off
     * the public storefront and stops taking bookings. Existing bookings are
     * intentionally left untouched — refunds and customer contact are handled
     * out of band via support. Idempotent: cancelling an already-cancelled
     * Event leaves the original timestamp in place. (Deletion vs. cancellation
     * rule.)
     */
    public function cancel(): bool
    {
        if ($this->cancelled_at === null) {
            $this->cancelled_at = now();
        }

        $this->is_published = false;

        return $this->save();
    }
}
