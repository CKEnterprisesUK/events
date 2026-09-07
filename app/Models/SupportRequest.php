<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A support ticket raised from the in-dashboard "Contact support" form by a
 * Company_User, addressed to the platform operator (Events by CK Enterprises
 * UK / the Super_Admins).
 *
 * Company-owned: the {@see BelongsToCompany} trait applies the global tenant
 * scope so a Company only ever sees its own tickets, and auto-fills
 * `company_id` on create from the active tenant. The raising user is recorded
 * on `user_id` (kept even if the user is later removed/anonymised).
 *
 * `access_consent` records whether the raiser ticked "allow CK Enterprises to
 * access my account to assist with this request" — a per-ticket authorisation
 * for Super_Admin access — and `access_consent_at` records when.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string $category
 * @property string $subject
 * @property string $message
 * @property string $status
 * @property bool $access_consent
 * @property Carbon|null $access_consent_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SupportRequest extends Model
{
    use BelongsToCompany;

    // ---- Categories ----------------------------------------------------------

    public const CATEGORY_ACCOUNT = 'account';

    public const CATEGORY_EVENTS = 'events';

    public const CATEGORY_ORDERS = 'orders';

    public const CATEGORY_PAYMENTS = 'payments';

    public const CATEGORY_BILLING = 'billing';

    public const CATEGORY_TECHNICAL = 'technical';

    public const CATEGORY_OTHER = 'other';

    /**
     * Category machine key → human label, used to render the picker and to
     * validate submitted values against a closed set.
     *
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        self::CATEGORY_ACCOUNT => 'My account & sign-in',
        self::CATEGORY_EVENTS => 'Events & tickets',
        self::CATEGORY_ORDERS => 'Orders, refunds & check-in',
        self::CATEGORY_PAYMENTS => 'Payments & Stripe payouts',
        self::CATEGORY_BILLING => 'Billing & platform fees',
        self::CATEGORY_TECHNICAL => 'A technical problem or bug',
        self::CATEGORY_OTHER => 'Something else',
    ];

    // ---- Statuses ------------------------------------------------------------

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    /**
     * Status machine key → human label. Note the product only exposes two
     * operator actions — reopen (open) and close (closed) — but the schema also
     * carries `in_progress`/`resolved` for future use, so all four are labelled.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_IN_PROGRESS => 'In progress',
        self::STATUS_RESOLVED => 'Resolved',
        self::STATUS_CLOSED => 'Closed',
    ];

    /**
     * `company_id` is mass-assignable because the "Contact support" form runs
     * OUTSIDE the tenant middleware (no active tenant to auto-fill it), so the
     * controller sets it explicitly from the acting user's Company. When a
     * tenant IS active the {@see BelongsToCompany} trait still fills it for you.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'user_id',
        'category',
        'subject',
        'message',
        'status',
        'access_consent',
        'access_consent_at',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_consent' => 'boolean',
            'access_consent_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * The user who raised the ticket (NULL once that user is removed).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The human label for this ticket's category.
     */
    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? ucfirst($this->category);
    }

    /**
     * The human label for this ticket's status.
     */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    /**
     * Whether this ticket is in a closed/terminal state (closed or resolved).
     * Anything else is treated as still needing attention ("open").
     */
    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_RESOLVED], true);
    }
}
