<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Records and queries per-user device trust.
 *
 * A device is trusted only after a successful TOTP challenge (Requirement 2.1);
 * once a `(user, device)` pair is trusted, later sign-ins from that same device
 * skip the two-factor challenge (Requirement 2.4). Trust is independent of the
 * Active_Company, so {@see TrustedDevice} carries no `company_id` and is not
 * tenant-scoped.
 *
 * The `device_id` is a client-supplied opaque identifier — the same value the
 * iOS client sends as the `X-Device-Id` header. A unique index on
 * `(user_id, device_id)` guarantees a device is trusted at most once per user,
 * which makes {@see trust()} idempotent.
 */
class DeviceTrustService
{
    /**
     * Whether a trust row exists for the given `(user, device)` pair.
     *
     * (Requirements 2.1, 2.4)
     */
    public function isTrusted(User $user, string $deviceId): bool
    {
        return TrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('device_id', $deviceId)
            ->exists();
    }

    /**
     * Trust a `(user, device)` pair, recording the moment trust was granted.
     *
     * Idempotent: given the `(user_id, device_id)` unique index, trusting an
     * already-trusted device updates the optional display name rather than
     * creating a duplicate row, and preserves the original `trusted_at`.
     *
     * (Requirements 2.1, 2.4)
     */
    public function trust(User $user, string $deviceId, ?string $name): TrustedDevice
    {
        $device = TrustedDevice::query()->firstOrNew([
            'user_id' => $user->getKey(),
            'device_id' => $deviceId,
        ]);

        if (! $device->exists) {
            $device->trusted_at = Carbon::now();
        }

        if ($name !== null) {
            $device->name = $name;
        }

        $device->save();

        return $device;
    }

    /**
     * Forget a `(user, device)` pair, revoking its trust. A device that was
     * never trusted is a no-op.
     *
     * (Requirement 2.4)
     */
    public function forget(User $user, string $deviceId): void
    {
        TrustedDevice::query()
            ->where('user_id', $user->getKey())
            ->where('device_id', $deviceId)
            ->delete();
    }
}
