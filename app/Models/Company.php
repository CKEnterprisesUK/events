<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A Company is a tenant on the Platform. It is identified internally by its
 * `id` (referenced as `company_id` on every Company-owned row) and publicly by
 * its unique `slug` used in path-based routing (`events.domain/{slug}/`).
 *
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $trading_name
 * @property string|null $organisation_type
 * @property string|null $company_number
 * @property string|null $charity_number
 * @property string|null $website
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $city
 * @property string|null $postcode
 * @property string $country
 * @property string $slug
 * @property string $status
 * @property string $fee_handling_mode
 * @property string|null $company_fee_percent
 * @property string|null $stripe_account_id
 * @property bool $stripe_charges_enabled
 * @property string $currency
 * @property string|null $primary_colour
 * @property string|null $logo_path
 * @property string|null $poster_path
 * @property string|null $about_text
 * @property string|null $facebook_url
 * @property string|null $instagram_url
 * @property string|null $x_url
 * @property string|null $linkedin_url
 * @property string|null $terms_url
 * @property string|null $privacy_url
 * @property string|null $terms_text
 * @property string|null $support_email
 * @property string|null $gdpr_contact_email
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /**
     * Fee handling modes: who bears the Application_Fee.
     */
    public const FEE_MODE_ABSORB = 'absorb';

    public const FEE_MODE_PASS_ON = 'pass_on';

    /**
     * The Fee_Handling_Mode values the Platform supports. (Requirement 13.1.)
     *
     * @var list<string>
     */
    public const FEE_MODES = [
        self::FEE_MODE_ABSORB,
        self::FEE_MODE_PASS_ON,
    ];

    /**
     * Company statuses covering the legal/verification lifecycle. Only
     * `suspended` gates the storefront/login (see EnsureCompanyActive and
     * ResolveTenant); `pending` (awaiting verification) and `closed`
     * (deactivated) are available for the verification workflow.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    /**
     * The Company statuses the Platform supports.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_CLOSED,
    ];

    /**
     * Organisation types a Company can register as. The legal name/registration
     * number requirements differ by type (Companies House vs Charity
     * Commission), enforced at the signup/settings layer.
     */
    public const TYPE_COMPANY = 'company';

    public const TYPE_CHARITY = 'charity';

    public const TYPE_CIC = 'cic';

    public const TYPE_SOLE_TRADER = 'sole_trader';

    public const TYPE_CLUB = 'club';

    public const TYPE_OTHER = 'other';

    /**
     * The organisation types the Platform supports, mapped to their
     * human-readable labels for form rendering.
     *
     * @var array<string, string>
     */
    public const ORGANISATION_TYPES = [
        self::TYPE_COMPANY => 'Company',
        self::TYPE_CHARITY => 'Charity',
        self::TYPE_CIC => 'CIC',
        self::TYPE_SOLE_TRADER => 'Sole trader',
        self::TYPE_CLUB => 'Club',
        self::TYPE_OTHER => 'Other',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'legal_name',
        'trading_name',
        'organisation_type',
        'company_number',
        'charity_number',
        'website',
        'email',
        'phone',
        'address_line_1',
        'address_line_2',
        'city',
        'postcode',
        'country',
        'slug',
        'status',
        'fee_handling_mode',
        'company_fee_percent',
        'stripe_account_id',
        'stripe_charges_enabled',
        'currency',
        'primary_colour',
        'logo_path',
        'poster_path',
        'about_text',
        'facebook_url',
        'instagram_url',
        'x_url',
        'linkedin_url',
        'terms_url',
        'privacy_url',
        'terms_text',
        'support_email',
        'gdpr_contact_email',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'country' => 'GB',
        // Default to passing the platform fee on to the customer as a booking
        // fee (added onto the ticket price). Owners can switch to absorbing it
        // from the Payments page. (Requirement 13.1)
        'fee_handling_mode' => self::FEE_MODE_PASS_ON,
        'stripe_charges_enabled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stripe_charges_enabled' => 'boolean',
            'company_fee_percent' => 'decimal:2',
        ];
    }

    /**
     * Whether the Company is suspended (storefront/login/sales disabled).
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Whether the Company can actually take card payments: it has a connected
     * Stripe account AND that account has charges enabled. This is the single
     * source of truth for payment readiness, reused at checkout (to gate a paid
     * purchase) and at publish time (to gate publishing an Event that sells paid
     * tickets). (Requirements 11.1, 11.5, 12.1)
     */
    public function canAcceptPayments(): bool
    {
        return $this->stripe_account_id !== null
            && (bool) $this->stripe_charges_enabled;
    }

    /**
     * Set and persist the Company's Fee_Handling_Mode. The Owner role gates the
     * call site (Requirement 13.3); this method enforces the value is one of
     * the supported modes (Requirement 13.1). Changing the mode affects only
     * Orders created afterwards — existing Orders snapshot the mode at creation
     * (Requirement 13.8), so nothing here mutates past Orders.
     *
     * @throws \InvalidArgumentException when $mode is not a supported mode.
     */
    public function setFeeHandlingMode(string $mode): bool
    {
        if (! in_array($mode, self::FEE_MODES, true)) {
            throw new \InvalidArgumentException("Unsupported fee handling mode: {$mode}");
        }

        $this->fee_handling_mode = $mode;

        return $this->save();
    }
}
