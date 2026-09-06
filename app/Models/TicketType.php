<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Ticket_Type is a category of ticket within an Event, with a name, price,
 * capacity, and sale window. (Requirement 6.1)
 *
 * Ticket_Types are Company-owned: the {@see BelongsToCompany} trait registers
 * the global `company_id` tenant scope and auto-fills `company_id` from the
 * resolved tenant on create, so a Ticket_Type always belongs to the active
 * Company and cross-Company rows never match.
 *
 * Money is held in integer minor currency units (`price_minor`, where 0 =
 * free — Requirement 6.3). `sold_count` is the confirmed sold quantity and
 * `reserved_count` is capacity held during active reservation windows. The
 * remaining available quantity is `capacity - sold_count - reserved_count`;
 * the {@see \App\Services\CapacityReservationService} enforces this (together
 * with the overall Event capacity) under `SELECT ... FOR UPDATE` so concurrent
 * checkouts serialize and never oversell. (Requirements 6.6, 6.7, 6.8, 5.6,
 * 10.6, 10.7)
 *
 * @property int $id
 * @property int $company_id
 * @property int $event_id
 * @property string $name
 * @property int $price_minor
 * @property int $capacity
 * @property int $sold_count
 * @property int $reserved_count
 * @property Carbon|null $sale_starts_at
 * @property Carbon|null $sale_ends_at
 */
class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'event_id',
        'name',
        'price_minor',
        'capacity',
        'sold_count',
        'reserved_count',
        'sale_starts_at',
        'sale_ends_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sold_count' => 0,
        'reserved_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'capacity' => 'integer',
            'sold_count' => 'integer',
            'reserved_count' => 'integer',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
        ];
    }

    /**
     * The Event this Ticket_Type belongs to.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Whether this Ticket_Type is free (price 0). (Requirement 6.3)
     */
    public function isFree(): bool
    {
        return $this->price_minor === 0;
    }

    /**
     * Remaining available quantity = capacity - sold_count - reserved_count.
     * (Requirements 6.6, 10.6)
     */
    public function availableQuantity(): int
    {
        return $this->capacity - $this->sold_count - $this->reserved_count;
    }

    /**
     * Whether `$now` falls within this Ticket_Type's sale window, treated as
     * the half-open interval `[sale_starts_at, sale_ends_at)`.
     *
     * The sale is open iff the current time is at or after the sale window
     * start and strictly before the sale window end. Consequently:
     *   - before the start → sale has not started (Requirement 6.4);
     *   - at or after the end → sale has ended (Requirement 6.5).
     *
     * This is a pure function of the Ticket_Type's window and the supplied
     * time; it does not consider Event publication (see {@see isPurchasableAt}).
     */
    public function isOnSaleAt(Carbon $now): bool
    {
        // A missing bound never opens the sale — the window is undefined.
        if ($this->sale_starts_at === null || $this->sale_ends_at === null) {
            return false;
        }

        return $now->greaterThanOrEqualTo($this->sale_starts_at)
            && $now->lessThan($this->sale_ends_at);
    }

    /**
     * Whether a Customer may view/purchase this Ticket_Type at `$now`.
     *
     * A Ticket_Type is purchasable if and only if its Event is published and
     * `$now` lies within the half-open sale window `[start, end)`.
     * (Requirements 5.5, 6.4, 6.5)
     */
    public function isPurchasableAt(Carbon $now): bool
    {
        return $this->event->isPublished() && $this->isOnSaleAt($now);
    }
}
