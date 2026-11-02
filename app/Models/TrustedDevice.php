<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A `(user, device)` pair that has passed a two-factor (TOTP) challenge.
 *
 * Once a device is trusted, later sign-ins from that same device skip the
 * challenge (Requirements 2.1, 2.4). Trust is per user and independent of the
 * Active_Company, so this model is NOT tenant-scoped (no `BelongsToCompany`)
 * and carries no `company_id`.
 *
 * The `device_id` is a client-supplied opaque identifier — the same value the
 * iOS client sends as the `X-Device-Id` header. A unique index on
 * `(user_id, device_id)` guarantees a device is trusted at most once per user.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_id
 * @property string|null $name
 * @property \Illuminate\Support\Carbon $trusted_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TrustedDevice extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'device_id',
        'name',
        'trusted_at',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trusted_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The User this device trust belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
