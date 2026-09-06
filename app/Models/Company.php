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
 * @property string $slug
 * @property string $status
 * @property string $fee_handling_mode
 * @property string|null $company_fee_percent
 * @property string|null $stripe_account_id
 * @property bool $stripe_charges_enabled
 * @property string $currency
 * @property string|null $primary_colour
 * @property string|null $logo_path
 * @property string|null $terms_text
 * @property string|null $support_email
 * @property string|null $gdpr_contact_email
 * @property array|null $ticket_field_defs
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
     * Company statuses.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'status',
        'fee_handling_mode',
        'company_fee_percent',
        'stripe_account_id',
        'stripe_charges_enabled',
        'currency',
        'primary_colour',
        'logo_path',
        'terms_text',
        'support_email',
        'gdpr_contact_email',
        'ticket_field_defs',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'fee_handling_mode' => self::FEE_MODE_ABSORB,
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
            'ticket_field_defs' => 'array',
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
