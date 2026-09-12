<?php

namespace App\Models;

use Database\Factories\PlatformSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide configuration, held as a single row. `global_fee_percent` is
 * the default Platform fee applied to Companies that have no
 * `company_fee_percent` override. (Requirements 20.5, 20.6.)
 *
 * @property int $id
 * @property string $global_fee_percent
 * @property string $stripe_fee_percent
 * @property int $stripe_fee_fixed_minor
 * @property string $mail_transport
 */
class PlatformSetting extends Model
{
    /** @use HasFactory<PlatformSettingFactory> */
    use HasFactory;

    /**
     * Default Platform fee percent used when the table is seeded and as the
     * schema-level column default.
     */
    public const DEFAULT_GLOBAL_FEE_PERCENT = '5.00';

    /**
     * Default estimate of Stripe's own card-processing fee, used for the
     * pre-purchase calculator and checkout preview. Reflects Stripe's UK
     * standard pricing (1.5% + £0.20) at time of writing; a Super_Admin adjusts
     * these if Stripe's rates change — they are DB-configured, never hardcoded
     * in .env/config. The exact fee on a settled order is captured separately
     * from the balance transaction. (Configurable-estimate feature)
     */
    public const DEFAULT_STRIPE_FEE_PERCENT = '1.50';

    public const DEFAULT_STRIPE_FEE_FIXED_MINOR = 20;

    /**
     * The supported outbound-mail transports for {@see self::$mail_transport}.
     * `smtp` is the default cPanel SMTP mailer; `graph` is the Microsoft Graph
     * API `sendMail` transport (organisation's own domain mailbox).
     */
    public const MAIL_TRANSPORT_SMTP = 'smtp';

    public const MAIL_TRANSPORT_GRAPH = 'graph';

    /**
     * @var list<string>
     */
    public const MAIL_TRANSPORTS = [
        self::MAIL_TRANSPORT_SMTP,
        self::MAIL_TRANSPORT_GRAPH,
    ];

    /**
     * Default outbound-mail transport: the existing SMTP mailer. Switching to
     * Graph is an explicit Super_Admin action on the Settings page.
     */
    public const DEFAULT_MAIL_TRANSPORT = self::MAIL_TRANSPORT_SMTP;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'global_fee_percent',
        'stripe_fee_percent',
        'stripe_fee_fixed_minor',
        'mail_transport',
    ];

    /**
     * @var array<string, string|int>
     */
    protected $attributes = [
        'global_fee_percent' => self::DEFAULT_GLOBAL_FEE_PERCENT,
        'stripe_fee_percent' => self::DEFAULT_STRIPE_FEE_PERCENT,
        'stripe_fee_fixed_minor' => self::DEFAULT_STRIPE_FEE_FIXED_MINOR,
        'mail_transport' => self::DEFAULT_MAIL_TRANSPORT,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'global_fee_percent' => 'decimal:2',
            'stripe_fee_percent' => 'decimal:2',
            'stripe_fee_fixed_minor' => 'integer',
        ];
    }

    /**
     * Return the single Platform settings row, creating it with defaults if it
     * does not yet exist.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
