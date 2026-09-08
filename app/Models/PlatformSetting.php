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
        'mail_transport',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'global_fee_percent' => self::DEFAULT_GLOBAL_FEE_PERCENT,
        'mail_transport' => self::DEFAULT_MAIL_TRANSPORT,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'global_fee_percent' => 'decimal:2',
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
