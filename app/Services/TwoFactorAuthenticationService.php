<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * All two-factor (TOTP, RFC 6238) mechanics for a {@see User}, kept out of the
 * controllers and the model so the crypto/secret handling lives in one audited
 * place. Backed by pragmarx/google2fa (the same library Laravel Fortify uses).
 *
 * Enrolment is two-phase so a user can never lock themselves out with a bad
 * setup:
 *   1. {@see startEnrolment()} writes a fresh secret + recovery codes but
 *      leaves `two_factor_confirmed_at` NULL — MFA is NOT yet active.
 *   2. {@see confirm()} verifies the user's first code and stamps
 *      `two_factor_confirmed_at`, activating the login challenge.
 *
 * The secret and recovery codes are written with `forceFill` because they are
 * deliberately excluded from the model's `$fillable`; they are encrypted at
 * rest by the model's casts.
 */
class TwoFactorAuthenticationService
{
    /** Number of one-time recovery codes issued per enrolment / regeneration. */
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $engine) {}

    /**
     * Begin (or restart) enrolment: generate a new secret and a fresh set of
     * recovery codes, persist them UNCONFIRMED. Any previous unconfirmed secret
     * is overwritten. Does not activate MFA — the login challenge only fires
     * once {@see confirm()} succeeds.
     */
    public function startEnrolment(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => $this->engine->generateSecretKey(),
            'two_factor_recovery_codes' => $this->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Confirm enrolment by checking the user's first TOTP code against the
     * pending secret. On success MFA becomes active and true is returned; on a
     * bad/expired code nothing changes and false is returned.
     */
    public function confirm(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        if (! $this->verifyTotp($user, $code)) {
            return false;
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Fully disable MFA for the user: clear the secret, recovery codes and the
     * confirmation stamp so no login challenge fires and enrolment starts clean
     * next time.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * Verify a 6-digit TOTP code against the user's secret. Whitespace is
     * stripped so a "123 456" paste still validates. Returns false when the
     * user has no secret.
     */
    public function verifyTotp(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        $code = preg_replace('/\s+/', '', $code);

        return $this->engine->verifyKey($user->two_factor_secret, (string) $code) !== false;
    }

    /**
     * Consume a one-time recovery code: if it matches one of the user's stored
     * codes, remove it (single-use) and return true. Comparison is
     * constant-time and case-insensitive on the hyphenated code. Returns false
     * for an unknown/already-used code.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = strtolower(trim($code));
        $codes = $user->two_factor_recovery_codes ?? [];

        $match = null;

        foreach ($codes as $stored) {
            if (hash_equals(strtolower($stored), $code)) {
                $match = $stored;
                break;
            }
        }

        if ($match === null) {
            return false;
        }

        $remaining = array_values(array_filter(
            $codes,
            static fn (string $c): bool => $c !== $match,
        ));

        $user->forceFill([
            'two_factor_recovery_codes' => $remaining,
        ])->save();

        return true;
    }

    /**
     * Issue a brand-new set of recovery codes, discarding the old ones. Used
     * both during enrolment and when a user regenerates codes from their
     * profile (e.g. after using several). Requires MFA to already have a secret.
     */
    public function regenerateRecoveryCodes(User $user): void
    {
        $user->forceFill([
            'two_factor_recovery_codes' => $this->generateRecoveryCodes(),
        ])->save();
    }

    /**
     * The inline SVG (data-URI) QR code the user scans into their authenticator
     * app, encoding an otpauth:// URI labelled with the app name and the user's
     * email. Returns null if there is no pending secret.
     */
    public function qrCodeInline(User $user): ?string
    {
        if ($user->two_factor_secret === null) {
            return null;
        }

        return $this->engine->getQRCodeInline(
            config('app.name'),
            $user->email,
            $user->two_factor_secret,
        );
    }

    /**
     * The raw Base32 secret, shown alongside the QR so a user whose device
     * cannot scan can key it in manually. Null when there is no pending secret.
     */
    public function secretForDisplay(User $user): ?string
    {
        return $user->two_factor_secret;
    }

    /**
     * Generate a fresh list of human-friendly one-time recovery codes in the
     * form `abcd-1234` (lowercase alphanumeric, hyphenated for readability).
     *
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        return Collection::times(self::RECOVERY_CODE_COUNT, static fn (): string => Str::lower(
            Str::random(4).'-'.Str::random(4),
        ))->all();
    }
}
