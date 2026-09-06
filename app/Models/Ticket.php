<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Ticket is a single purchased or claimed ticket within an Order, recording
 * its Ticket_Type. The Platform creates exactly one Ticket per purchased or
 * claimed ticket. (Requirement 10.5)
 *
 * Tickets are Company-owned: the {@see BelongsToCompany} trait registers the
 * global `company_id` tenant scope and auto-fills `company_id` from the
 * resolved tenant on create, so a Ticket always belongs to the active Company
 * and cross-Company rows never match.
 *
 * `status` is `valid` on creation and flipped to `voided` when the owning Order
 * is cancelled/refunded in a later slice. (Requirement 17.3)
 *
 * @property int $id
 * @property int $company_id
 * @property int $order_id
 * @property int $ticket_type_id
 * @property string $status
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use BelongsToCompany, HasFactory;

    public const STATUS_VALID = 'valid';

    public const STATUS_VOIDED = 'voided';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'order_id',
        'ticket_type_id',
        'status',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_VALID,
    ];

    /**
     * The Order this Ticket belongs to.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The Ticket_Type this Ticket was purchased/claimed against.
     *
     * @return BelongsTo<TicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }
}
