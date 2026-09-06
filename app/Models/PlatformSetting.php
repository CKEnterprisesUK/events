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
     * @var list<string>
     */
    protected $fillable = [
        'global_fee_percent',
    ];

    /**
     * @var array<string, string>
     */
    protected $attributes = [
        'global_fee_percent' => self::DEFAULT_GLOBAL_FEE_PERCENT,
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
