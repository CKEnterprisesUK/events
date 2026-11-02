<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scan attempt at the door — a hit or a miss.
 *
 * Every time an operator scans a code, the outcome is recorded here: a
 * successful check-in, a duplicate (ticket already scanned), or an error
 * (unreadable/invalid/foreign/void). This is the central, cross-operator
 * record the live event dashboard reads so the whole door team's activity
 * shows on one screen in real time — the per-operator session recap in
 * {@see \App\Http\Controllers\ScanController} is only a convenience for the
 * person holding that phone.
 *
 * Append-only and operational: the authoritative "checked in" state lives on
 * `orders.scanned_at`; a row here is a lightweight breadcrumb. Tenant-scoped
 * via {@see BelongsToCompany}.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $event_id
 * @property int|null $order_id
 * @property int|null $operator_id
 * @property string|null $operator_name
 * @property string $outcome
 * @property string|null $reference
 * @property string|null $customer_name
 * @property int $tickets
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class ScanEvent extends Model
{
    use BelongsToCompany;

    /** A ticket was checked in successfully. */
    public const OUTCOME_SUCCESS = 'success';

    /** The ticket had already been scanned (a repeat / double entry attempt). */
    public const OUTCOME_DUPLICATE = 'duplicate';

    /** The code verified but matched no Order / a non-confirmed Order. */
    public const OUTCOME_INVALID = 'invalid';

    /** The code resolved to an Order of another organisation. */
    public const OUTCOME_REJECTED = 'rejected';

    /** The code could not be read / decoded at all. */
    public const OUTCOME_UNREADABLE = 'unreadable';

    /** The ticket is no longer valid (voided / refunded order). */
    public const OUTCOME_FAILED = 'failed';

    /**
     * Outcomes that represent a problem at the door (anything that is not a
     * clean first-time check-in). Used to tally the "issues" figure on the live
     * dashboard.
     *
     * @var list<string>
     */
    public const PROBLEM_OUTCOMES = [
        self::OUTCOME_DUPLICATE,
        self::OUTCOME_INVALID,
        self::OUTCOME_REJECTED,
        self::OUTCOME_UNREADABLE,
        self::OUTCOME_FAILED,
    ];

    /**
     * Timestamps are managed manually (created_at only — rows are immutable),
     * so Eloquent's updated_at handling is off.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'event_id',
        'order_id',
        'operator_id',
        'operator_name',
        'outcome',
        'reference',
        'customer_name',
        'tickets',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tickets' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The Event this scan was for, when the code resolved to one.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The Order the code matched, when any.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The operator (User) who performed the scan, when still present.
     *
     * @return BelongsTo<User, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * Whether this scan is a clean first-time check-in.
     */
    public function isSuccess(): bool
    {
        return $this->outcome === self::OUTCOME_SUCCESS;
    }
}
