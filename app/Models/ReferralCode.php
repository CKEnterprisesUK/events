<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Super_Admin-created code that, when used at signup, sets the new
 * Company's fee percent to {@see self::$fee_percent}.
 *
 * @property int $id
 * @property string $code
 * @property string|null $label
 * @property string $fee_percent
 * @property int|null $max_uses
 * @property int $uses_count
 * @property bool $is_active
 */
class ReferralCode extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'label', 'fee_percent', 'max_uses', 'uses_count', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fee_percent' => 'decimal:2',
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Codes are case-insensitive and stored upper-case. */
    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function findUsable(?string $code): ?self
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $referral = self::query()->where('code', self::normalise($code))->first();

        return $referral?->isUsable() ? $referral : null;
    }

    public function isUsable(): bool
    {
        return $this->is_active
            && ($this->max_uses === null || $this->uses_count < $this->max_uses);
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = self::normalise($value);
    }
}
