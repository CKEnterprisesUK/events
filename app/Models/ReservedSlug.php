<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A ReservedSlug is a Company_Slug the Platform will never hand out.
 *
 * Path-based tenancy routes every storefront at `/{company-slug}/...`, so a
 * slug that collides with a reserved top-level prefix (`login`, `admin`,
 * `dashboard`, `trust`, `webhooks`, ...) or an infrastructure path (`api`,
 * `assets`, `.well-known`, ...) would produce an unreachable/confusing
 * storefront and could break silently if a future reserved prefix is added.
 * The list also holds brand/abuse words (impersonation-friendly names) the
 * Platform declines to hand out.
 *
 * The blocklist is enforced by {@see \App\Rules\CompanySlug} on self-signup and
 * on any slug change, and is managed by a Super_Admin on the `/admin` surface.
 *
 * Rows flagged {@see $is_system} are the seeded technical/infra reserved words:
 * they are surfaced in the admin UI but cannot be deleted (removing one would
 * let a storefront shadow a real route). Operator-added words are freely
 * removable.
 *
 * @property int $id
 * @property string $slug
 * @property string|null $reason
 * @property bool $is_system
 */
class ReservedSlug extends Model
{
    /**
     * Seeded technical/infrastructure and brand/abuse reserved slugs, mapped to
     * the reason surfaced in the admin UI. These are inserted as `is_system`
     * rows (undeletable) so the blocklist is never empty on a fresh install and
     * the words that would shadow real routes can never be removed.
     *
     * Keep this in sync with the seed block in
     * `database/sql/030_create_reserved_slugs.sql`.
     *
     * @var array<string, string>
     */
    public const SYSTEM_DEFAULTS = [
        // Reserved platform routes (declared before the storefront catch-all).
        'admin' => 'Reserved platform route',
        'login' => 'Reserved platform route',
        'logout' => 'Reserved platform route',
        'register' => 'Reserved platform route',
        'dashboard' => 'Reserved platform route',
        'invitations' => 'Reserved platform route',
        'trust' => 'Reserved platform route',
        'privacy' => 'Reserved platform route',
        'webhooks' => 'Reserved platform route',
        'forgot-password' => 'Reserved platform route',
        'reset-password' => 'Reserved platform route',
        'email' => 'Reserved platform route',
        'verify-email' => 'Reserved platform route',

        // Reserved infrastructure paths.
        'api' => 'Reserved infrastructure path',
        'assets' => 'Reserved infrastructure path',
        'storage' => 'Reserved infrastructure path',
        'build' => 'Reserved infrastructure path',
        'css' => 'Reserved infrastructure path',
        'js' => 'Reserved infrastructure path',
        'fonts' => 'Reserved infrastructure path',
        'images' => 'Reserved infrastructure path',
        'img' => 'Reserved infrastructure path',
        '.well-known' => 'Reserved infrastructure path',
        'robots.txt' => 'Reserved infrastructure path',
        'sitemap.xml' => 'Reserved infrastructure path',
        'favicon.ico' => 'Reserved infrastructure path',

        // Brand / impersonation risk.
        'www' => 'Reserved / impersonation risk',
        'mail' => 'Reserved / impersonation risk',
        'support' => 'Reserved / impersonation risk',
        'help' => 'Reserved / impersonation risk',
        'billing' => 'Reserved / impersonation risk',
        'account' => 'Reserved / impersonation risk',
        'settings' => 'Reserved / impersonation risk',
        'official' => 'Reserved / impersonation risk',
        'events' => 'Reserved / impersonation risk',
        'ckenterprises' => 'Reserved / impersonation risk',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'reason',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Normalise a candidate slug to the canonical stored form: trimmed and
     * lowercased. Reserved slugs are compared case-insensitively, and Company
     * slugs are always lowercase, so storing/comparing lowercase is sufficient.
     */
    public static function normalise(string $slug): string
    {
        return Str::lower(trim($slug));
    }

    /**
     * Whether the given candidate slug is on the blocklist. Case-insensitive.
     */
    public static function isReserved(string $slug): bool
    {
        $candidate = self::normalise($slug);

        if ($candidate === '') {
            return false;
        }

        return static::query()->where('slug', $candidate)->exists();
    }

    /**
     * Every reserved slug for the admin management surface, ordered so system
     * rows group together and the list reads alphabetically within a group.
     * Ensures the system defaults exist first.
     *
     * @return Collection<int, self>
     */
    public static function forManagement(): Collection
    {
        static::ensureSystemDefaults();

        return static::query()
            ->orderByDesc('is_system')
            ->orderBy('slug')
            ->get();
    }

    /**
     * Create any missing system reserved slugs so the blocklist is never empty
     * and the route-shadowing words are always present. Idempotent — never
     * overwrites an existing row (an operator may have added the same word
     * first; leave its row as-is).
     */
    public static function ensureSystemDefaults(): void
    {
        $existing = static::query()->pluck('slug')->all();

        foreach (self::SYSTEM_DEFAULTS as $slug => $reason) {
            if (in_array($slug, $existing, true)) {
                continue;
            }

            static::query()->create([
                'slug' => $slug,
                'reason' => $reason,
                'is_system' => true,
            ]);
        }
    }
}
