<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An append-only audit record of a security-, money-, access-, or privacy-
 * sensitive action: who did it, to what, when, and from where.
 *
 * Deliberately NOT tenant-scoped (no {@see BelongsToCompany}
 * trait): system/webhook events may have no acting user or Company, and
 * Super_Admin actions must be visible cross-tenant. Callers scope by
 * `company_id` explicitly (the organiser view) or read everything (the
 * super-admin view). Rows are immutable — only `created_at` is used, there is
 * no `updated_at` and nothing updates or deletes a row except the retention
 * prune sweep.
 *
 * @property int $id
 * @property int|null $company_id
 * @property int|null $actor_user_id
 * @property string|null $actor_label
 * @property string $actor_type
 * @property bool $is_impersonated
 * @property int|null $impersonator_user_id
 * @property string $action
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property string|null $summary
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /**
     * Rows are immutable and carry only a creation instant, so Eloquent's
     * `updated_at` handling is disabled and only `created_at` is maintained.
     */
    public const UPDATED_AT = null;

    // ---- Actor types ---------------------------------------------------------

    public const ACTOR_USER = 'user';

    public const ACTOR_SUPER_ADMIN = 'super_admin';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_CUSTOMER = 'customer';

    // ---- Categories (for grouping/filtering in the UI) -----------------------

    public const CATEGORY_AUTH = 'auth';

    public const CATEGORY_ACCESS = 'access';

    public const CATEGORY_MONEY = 'money';

    public const CATEGORY_EVENTS = 'events';

    public const CATEGORY_PRIVACY = 'privacy';

    public const CATEGORY_SYSTEM = 'system';

    // ---- Action machine keys (`subject.verb`, past tense) --------------------

    // Auth & security
    public const AUTH_LOGIN_SUCCEEDED = 'auth.login_succeeded';

    public const AUTH_LOGIN_FAILED = 'auth.login_failed';

    public const IMPERSONATION_STARTED = 'impersonation.started';

    public const IMPERSONATION_STOPPED = 'impersonation.stopped';

    // Access changes
    public const USER_INVITED = 'user.invited';

    public const USER_ROLE_CHANGED = 'user.role_changed';

    public const USER_REMOVED = 'user.removed';

    public const COMPANY_SUSPENDED = 'company.suspended';

    public const COMPANY_UNSUSPENDED = 'company.unsuspended';

    // Money
    public const ORDER_CANCELLED = 'order.cancelled';

    public const ORDER_REFUNDED = 'order.refunded';

    public const COMP_ISSUED = 'comp.issued';

    public const FEE_MODE_CHANGED = 'fee.mode_changed';

    public const FEE_GLOBAL_CHANGED = 'fee.global_changed';

    public const FEE_COMPANY_CHANGED = 'fee.company_changed';

    public const STRIPE_ONBOARDING_STARTED = 'stripe.onboarding_started';

    public const STRIPE_CHARGES_ENABLED_CHANGED = 'stripe.charges_enabled_changed';

    // Events & tickets
    public const EVENT_CREATED = 'event.created';

    public const EVENT_UPDATED = 'event.updated';

    public const EVENT_PUBLISHED = 'event.published';

    public const EVENT_UNPUBLISHED = 'event.unpublished';

    public const TICKET_TYPE_CREATED = 'ticket_type.created';

    public const TICKET_TYPE_UPDATED = 'ticket_type.updated';

    public const ORDER_TICKET_RESENT = 'order.ticket_resent';

    // Privacy / GDPR
    public const GDPR_CUSTOMER_EXPORTED = 'gdpr.customer_exported';

    public const GDPR_CUSTOMERS_EXPORTED = 'gdpr.customers_exported';

    public const GDPR_CUSTOMER_ANONYMISED = 'gdpr.customer_anonymised';

    // System (no actor)
    public const WEBHOOK_PAYMENT_CONFIRMED = 'webhook.payment_confirmed';

    public const WEBHOOK_REFUND_PROCESSED = 'webhook.refund_processed';

    public const WEBHOOK_DISPUTE_CREATED = 'webhook.dispute_created';

    /**
     * Every known action mapped to its (category, human label). The single
     * source of truth for how the UI groups, filters and titles a row. Any
     * action not present here still stores/displays via its raw key.
     *
     * @var array<string, array{category: string, label: string}>
     */
    public const ACTIONS = [
        self::AUTH_LOGIN_SUCCEEDED => ['category' => self::CATEGORY_AUTH, 'label' => 'Signed in'],
        self::AUTH_LOGIN_FAILED => ['category' => self::CATEGORY_AUTH, 'label' => 'Failed sign-in'],
        self::IMPERSONATION_STARTED => ['category' => self::CATEGORY_AUTH, 'label' => 'Started impersonation'],
        self::IMPERSONATION_STOPPED => ['category' => self::CATEGORY_AUTH, 'label' => 'Stopped impersonation'],

        self::USER_INVITED => ['category' => self::CATEGORY_ACCESS, 'label' => 'Invited a user'],
        self::USER_ROLE_CHANGED => ['category' => self::CATEGORY_ACCESS, 'label' => 'Changed a user role'],
        self::USER_REMOVED => ['category' => self::CATEGORY_ACCESS, 'label' => 'Removed a user'],
        self::COMPANY_SUSPENDED => ['category' => self::CATEGORY_ACCESS, 'label' => 'Suspended a company'],
        self::COMPANY_UNSUSPENDED => ['category' => self::CATEGORY_ACCESS, 'label' => 'Unsuspended a company'],

        self::ORDER_CANCELLED => ['category' => self::CATEGORY_MONEY, 'label' => 'Cancelled an order'],
        self::ORDER_REFUNDED => ['category' => self::CATEGORY_MONEY, 'label' => 'Refunded an order'],
        self::COMP_ISSUED => ['category' => self::CATEGORY_MONEY, 'label' => 'Issued complimentary tickets'],
        self::FEE_MODE_CHANGED => ['category' => self::CATEGORY_MONEY, 'label' => 'Changed fee handling'],
        self::FEE_GLOBAL_CHANGED => ['category' => self::CATEGORY_MONEY, 'label' => 'Changed the global fee'],
        self::FEE_COMPANY_CHANGED => ['category' => self::CATEGORY_MONEY, 'label' => 'Changed a company fee'],
        self::STRIPE_ONBOARDING_STARTED => ['category' => self::CATEGORY_MONEY, 'label' => 'Started Stripe onboarding'],
        self::STRIPE_CHARGES_ENABLED_CHANGED => ['category' => self::CATEGORY_MONEY, 'label' => 'Stripe charges status changed'],

        self::EVENT_CREATED => ['category' => self::CATEGORY_EVENTS, 'label' => 'Created an event'],
        self::EVENT_UPDATED => ['category' => self::CATEGORY_EVENTS, 'label' => 'Updated an event'],
        self::EVENT_PUBLISHED => ['category' => self::CATEGORY_EVENTS, 'label' => 'Published an event'],
        self::EVENT_UNPUBLISHED => ['category' => self::CATEGORY_EVENTS, 'label' => 'Unpublished an event'],
        self::TICKET_TYPE_CREATED => ['category' => self::CATEGORY_EVENTS, 'label' => 'Created a ticket type'],
        self::TICKET_TYPE_UPDATED => ['category' => self::CATEGORY_EVENTS, 'label' => 'Updated a ticket type'],
        self::ORDER_TICKET_RESENT => ['category' => self::CATEGORY_EVENTS, 'label' => 'Re-sent a ticket email'],

        self::GDPR_CUSTOMER_EXPORTED => ['category' => self::CATEGORY_PRIVACY, 'label' => 'Exported customer data'],
        self::GDPR_CUSTOMERS_EXPORTED => ['category' => self::CATEGORY_PRIVACY, 'label' => 'Exported the customer list'],
        self::GDPR_CUSTOMER_ANONYMISED => ['category' => self::CATEGORY_PRIVACY, 'label' => 'Anonymised customer data'],

        self::WEBHOOK_PAYMENT_CONFIRMED => ['category' => self::CATEGORY_SYSTEM, 'label' => 'Payment confirmed'],
        self::WEBHOOK_REFUND_PROCESSED => ['category' => self::CATEGORY_SYSTEM, 'label' => 'Refund processed'],
        self::WEBHOOK_DISPUTE_CREATED => ['category' => self::CATEGORY_SYSTEM, 'label' => 'Dispute opened'],
    ];

    /**
     * Human-friendly labels for the categories, in display order.
     *
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        self::CATEGORY_MONEY => 'Money',
        self::CATEGORY_ACCESS => 'Access',
        self::CATEGORY_EVENTS => 'Events & tickets',
        self::CATEGORY_PRIVACY => 'Privacy',
        self::CATEGORY_AUTH => 'Sign-in & security',
        self::CATEGORY_SYSTEM => 'System',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'actor_user_id',
        'actor_label',
        'actor_type',
        'is_impersonated',
        'impersonator_user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'summary',
        'context',
        'ip_address',
        'created_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'actor_type' => self::ACTOR_USER,
        'is_impersonated' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_impersonated' => 'boolean',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The category key for this row's action, or the system category when the
     * action is unknown to the catalogue.
     */
    public function category(): string
    {
        return self::ACTIONS[$this->action]['category'] ?? self::CATEGORY_SYSTEM;
    }

    /**
     * The human label for this row's action, falling back to the raw key.
     */
    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action]['label'] ?? $this->action;
    }

    /**
     * The Company this record belongs to (NULL for platform/system events).
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The user who performed the action (NULL for system/customer events).
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * The Super_Admin behind an impersonated action (NULL otherwise).
     *
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }

    /**
     * The polymorphic subject the action was performed on, when one was
     * recorded.
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
