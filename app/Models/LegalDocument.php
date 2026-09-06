<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A LegalDocument is a single Platform-level policy in the Trust & Legal Centre
 * (e.g. Terms & Conditions, Privacy Notice, PCI DSS statement, cookie policy).
 * These are Platform documents owned by Events by CK Enterprises UK, authored
 * and maintained by a Super_Admin, published at public `/trust` URLs and linked
 * from the site footer.
 *
 * They are distinct from the per-Company `terms_text`/`privacy_text` (the
 * organiser's own legal shown to customers at checkout): those belong to a
 * tenant, these belong to the Platform.
 *
 * Held as multiple rows keyed by a stable {@see $slug}, so the set of policies
 * is extensible from the admin without further schema changes. The constants
 * below are the well-known defaults the app seeds and references by name.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $body
 * @property bool $is_published
 * @property int $sort_order
 */
class LegalDocument extends Model
{
    public const SLUG_TERMS = 'terms';

    public const SLUG_PRIVACY = 'privacy';

    public const SLUG_PCI = 'pci';

    public const SLUG_COOKIES = 'cookies';

    public const SLUG_ACCEPTABLE_USE = 'acceptable-use';

    /**
     * The well-known documents the Trust & Legal Centre ships with, in display
     * order. Each maps a stable slug to its default title. A Super_Admin can
     * edit the title/body and publish them, and add further documents.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        self::SLUG_TERMS => 'Terms & Conditions',
        self::SLUG_PRIVACY => 'Privacy Notice',
        self::SLUG_PCI => 'PCI DSS Standard',
        self::SLUG_COOKIES => 'Cookie Policy',
        self::SLUG_ACCEPTABLE_USE => 'Acceptable Use Policy',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'title',
        'body',
        'is_published',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Whether this document has authored content to show publicly.
     */
    public function hasBody(): bool
    {
        return filled($this->body);
    }

    /**
     * The published documents that have content, ordered for the public Trust &
     * Legal Centre hub. Drafts and empty documents are excluded.
     *
     * @return Collection<int, self>
     */
    public static function published(): Collection
    {
        return static::query()
            ->where('is_published', true)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }

    /**
     * Every document for the admin management surface, ordered consistently.
     * Ensures the well-known defaults exist as (unpublished) rows so a
     * Super_Admin always has them to author, then returns the full set.
     *
     * @return Collection<int, self>
     */
    public static function forManagement(): Collection
    {
        static::ensureDefaults();

        return static::query()
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }

    /**
     * Create any missing well-known default documents as unpublished drafts, so
     * the standard set (Terms, Privacy, PCI, etc.) is always present for a
     * Super_Admin to author. Idempotent — never overwrites existing rows.
     */
    public static function ensureDefaults(): void
    {
        $existing = static::query()->pluck('slug')->all();
        $order = 0;

        foreach (self::DEFAULTS as $slug => $title) {
            $order++;

            if (in_array($slug, $existing, true)) {
                continue;
            }

            static::query()->create([
                'slug' => $slug,
                'title' => $title,
                'body' => null,
                'is_published' => false,
                'sort_order' => $order,
            ]);
        }
    }
}
