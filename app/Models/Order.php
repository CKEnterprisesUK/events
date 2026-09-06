<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An Order is a Customer's checkout against an Event. (Requirement 10.1)
 *
 * Orders are Company-owned: the {@see BelongsToCompany} trait registers the
 * global `company_id` tenant scope and auto-fills `company_id` from the
 * resolved tenant on create, so an Order always belongs to the active Company
 * and cross-Company rows never match.
 *
 * `order_reference` is unique across the entire Platform (Requirement 10.13).
 * All money is held in integer minor currency units, snapshotted at creation
 * along with `fee_handling_mode` so a later change to the Company's fee mode
 * never mutates an existing Order (Requirement 13.8). The Order is created in
 * `reserved` status with `reserved_until` set to now + 900s; the scheduled
 * release job expires holds whose window elapses (Requirements 10.6, 10.7).
 *
 * @property int $id
 * @property int $company_id
 * @property int $event_id
 * @property string $order_reference
 * @property string $customer_name
 * @property string $customer_email
 * @property string $status
 * @property int $ticket_subtotal_minor
 * @property int $booking_fee_minor
 * @property int $application_fee_minor
 * @property int $order_total_minor
 * @property int $refunded_total_minor
 * @property string $fee_handling_mode
 * @property Carbon|null $reserved_until
 * @property string|null $stripe_session_id
 * @property string|null $stripe_charge_id
 * @property string|null $stripe_payment_intent_id
 * @property Carbon|null $scanned_at
 * @property int|null $scanned_by
 * @property Carbon|null $fulfilled_at
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToCompany, HasFactory;

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_PAID = 'paid';

    public const STATUS_FREE_CONFIRMED = 'free_confirmed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_VOIDED = 'voided';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'event_id',
        'order_reference',
        'customer_name',
        'customer_email',
        'status',
        'ticket_subtotal_minor',
        'booking_fee_minor',
        'application_fee_minor',
        'order_total_minor',
        'refunded_total_minor',
        'fee_handling_mode',
        'reserved_until',
        'stripe_session_id',
        'stripe_charge_id',
        'stripe_payment_intent_id',
        'scanned_at',
        'scanned_by',
        'fulfilled_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_RESERVED,
        'ticket_subtotal_minor' => 0,
        'booking_fee_minor' => 0,
        'application_fee_minor' => 0,
        'order_total_minor' => 0,
        'refunded_total_minor' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_subtotal_minor' => 'integer',
            'booking_fee_minor' => 'integer',
            'application_fee_minor' => 'integer',
            'order_total_minor' => 'integer',
            'refunded_total_minor' => 'integer',
            'reserved_until' => 'datetime',
            'scanned_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    /**
     * The Event this Order was placed against.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The Tickets making up this Order — one row per purchased/claimed ticket.
     * (Requirement 10.5)
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * The consent selections captured for this Order at checkout.
     * (Requirements 10.3, 10.4, 22.4)
     *
     * @return HasMany<OrderConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(OrderConsent::class);
    }

    /**
     * Whether this Order consists solely of free tickets (zero total).
     * (Requirements 10.9, 13.7)
     */
    public function isFree(): bool
    {
        return $this->order_total_minor === 0;
    }

    /**
     * Whether this Order has been confirmed — paid via the webhook, or
     * free-confirmed at checkout. These are the two states that trigger
     * fulfilment. (Requirements 12.6, 10.9, 14.1)
     */
    public function isConfirmed(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_FREE_CONFIRMED], true);
    }

    /**
     * Whether this Order has already been fulfilled (QR issued, capacity
     * committed, ticket email enqueued). The idempotency guard for fulfilment.
     * (Requirement 14.3)
     */
    public function isFulfilled(): bool
    {
        return $this->fulfilled_at !== null;
    }

    /**
     * The amount still refundable on this Order, in integer minor units: the
     * order total less everything already refunded. Never negative. A partial
     * refund may not exceed this, and reaching zero means the Order has been
     * refunded in full. (Requirement 17.2)
     */
    public function refundableRemainingMinor(): int
    {
        return max(0, $this->order_total_minor - $this->refunded_total_minor);
    }

    /**
     * Whether the cumulative refunds on this paid Order have reached its full
     * total, so no further amount can be refunded. (Requirement 17.2)
     */
    public function isFullyRefunded(): bool
    {
        return $this->order_total_minor > 0
            && $this->refunded_total_minor >= $this->order_total_minor;
    }
}
