<?php

namespace App\Models;

use App\Notifications\VerifyEmailNow;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A Platform user. Either a Company_User (belonging to one Company via
 * `company_id`, holding exactly one of the Company roles) or a CK
 * Enterprises Super_Admin (`is_super_admin = true`, `company_id`/`role` NULL,
 * operating the separate super-admin surface).
 *
 * The User model deliberately does NOT use the `BelongsToCompany` global tenant
 * scope: users are looked up in auth/dashboard context (reserved prefixes,
 * where no tenant is resolved), so scoping them by the request-resolved Company
 * would break login and Super_Admin access. Session/data scoping to the user's
 * own Company is enforced via `company_id` comparisons (see `belongsToCompany`)
 * and the role policies instead. (Requirements 3.1, 3.8, 3.10, 20.1)
 *
 * @property int $id
 * @property int|null $company_id
 * @property bool $is_super_admin
 * @property string|null $role
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Carbon|null $agreed_to_terms_at
 * @property Carbon|null $last_activity_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $mfa_prompt_dismissed_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The Company roles the Platform supports. (Requirement 3.1)
     */
    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_BOX_OFFICE = 'box_office';

    public const ROLE_ACCOUNTANT = 'accountant';

    public const ROLE_SCANNER = 'scanner';

    /**
     * The complete, closed set of Company roles. (Requirement 3.1)
     *
     * @var list<string>
     */
    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_BOX_OFFICE,
        self::ROLE_ACCOUNTANT,
        self::ROLE_SCANNER,
    ];

    /**
     * Roles that may be assigned by invitation (Owner is not invitable and is
     * only ever the single seeded/transferred Owner). (Requirement 4.6)
     *
     * @var list<string>
     */
    public const INVITABLE_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_BOX_OFFICE,
        self::ROLE_ACCOUNTANT,
        self::ROLE_SCANNER,
    ];

    /**
     * Human-friendly labels and one-line descriptions for each Company role,
     * used by the team page's role capabilities table.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const ROLE_META = [
        self::ROLE_OWNER => [
            'label' => 'Owner',
            'description' => 'Full access, including billing, Stripe, company settings and the team.',
        ],
        self::ROLE_ADMIN => [
            'label' => 'Admin',
            'description' => 'Runs events, ticketing and orders, and handles GDPR requests.',
        ],
        self::ROLE_BOX_OFFICE => [
            'label' => 'Box office',
            'description' => 'Runs events, ticketing and orders, but cannot change company settings.',
        ],
        self::ROLE_ACCOUNTANT => [
            'label' => 'Accountant',
            'description' => 'Read-only access to reports and payouts.',
        ],
        self::ROLE_SCANNER => [
            'label' => 'Scanner',
            'description' => 'Checks in attendees at the door only.',
        ],
    ];

    /**
     * The human-friendly label for a role value, falling back to the raw value.
     */
    public static function roleLabel(?string $role): string
    {
        return self::ROLE_META[$role]['label'] ?? ucfirst((string) $role);
    }

    /**
     * Mass-assignable attributes.
     *
     * `is_super_admin` is deliberately EXCLUDED: it is the platform's highest
     * privilege and is never set through a request/mass-assignment path (a
     * Super_Admin is provisioned out-of-band, and the factory sets the flag via
     * `forceFill`, which bypasses `$fillable`). Excluding it means that even if a
     * future write path accidentally forwards untrusted input into
     * `User::create()`/`->update()`, an attacker cannot escalate to Super_Admin.
     * Set it explicitly with `forceFill(['is_super_admin' => true])` when needed.
     *
     * `role` remains fillable because it is written by two trusted paths
     * (registration hardcodes Owner; invitation-accept copies the invitation's
     * role) and is validated with `Rule::in(...)` at every entry point, so it can
     * never elevate a user beyond the closed Company-role set.
     *
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'role',
        'name',
        'email',
        'password',
        'agreed_to_terms_at',
        'last_activity_at',
        // Harmless UI preference (suppresses the post-login MFA nudge). Unlike
        // the `two_factor_*` secret columns — which stay OUT of `$fillable` and
        // are only written via the service's `forceFill` — this carries no
        // privilege, so it is safe to mass-assign (and lets factories set it).
        'mfa_prompt_dismissed_at',
    ];

    /**
     * The two-factor columns are deliberately EXCLUDED from `$fillable`: like
     * `is_super_admin`, they are security-critical and are only ever written
     * through the dedicated {@see \App\Services\TwoFactorAuthenticationService}
     * (via `forceFill`), never from mass-assignment of request input.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_super_admin' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'agreed_to_terms_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'password' => 'hashed',
            // Encrypted at rest: the raw TOTP secret and the recovery-code list
            // never touch the DB in plaintext. `encrypted:array` also handles
            // the JSON (de)serialisation of the recovery codes for us.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'mfa_prompt_dismissed_at' => 'datetime',
        ];
    }

    /**
     * Send the email-verification notification IMMEDIATELY.
     *
     * Overrides the framework default so verification uses {@see VerifyEmailNow}
     * — a notification that is NOT queued — rather than waiting for the
     * per-minute cron queue burst. Every other Platform email keeps its queued,
     * cron-drained delivery. (See DEPLOYMENT.md for the queue/cron setup.)
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNow);
    }

    /**
     * The Company this user belongs to (NULL for Super_Admins).
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Whether this user holds the given Company role.
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Whether this user is the (single) Owner of their Company. (3.2)
     */
    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    /**
     * Whether this user is a CK Enterprises Super_Admin. (20.1, 20.7)
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Whether this user belongs to the given Company. Used to scope an
     * authenticated session to the user's own Company and to reject
     * cross-Company access. (Requirements 3.8, 3.10)
     */
    public function belongsToCompany(int|Company $company): bool
    {
        if ($this->company_id === null) {
            return false;
        }

        $companyId = $company instanceof Company ? $company->getKey() : $company;

        return $this->company_id === $companyId;
    }

    /**
     * Whether this user has ACTIVE two-factor authentication — a secret that
     * has been confirmed with a valid code. A half-finished enrolment (secret
     * present but never confirmed) does NOT count, so such a user is not
     * challenged at login and can safely restart enrolment. (MFA opt-in)
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null
            && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Whether the post-login "MFA recommended" nudge should be shown to this
     * user. Shown to any Company_User (never Super_Admins) who has not enabled
     * MFA and has not permanently dismissed the reminder. Returning false once
     * MFA is on keeps the nudge from ever reappearing.
     */
    public function shouldSeeMfaRecommendation(): bool
    {
        if ($this->isSuperAdmin()) {
            return false;
        }

        return ! $this->hasTwoFactorEnabled()
            && $this->mfa_prompt_dismissed_at === null;
    }
}
