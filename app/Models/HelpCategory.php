<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A HelpCategory is a top-level section of the Knowledge Base (e.g. Getting
 * started, Events & tickets, Payments & payouts). It groups related
 * {@see HelpArticle}s and is the DB-backed replacement for the hardcoded
 * sections that used to live in `App\Support\HelpCentre`.
 *
 * Platform content owned by Events by CK Enterprises UK, authored by a
 * Super_Admin, published at the public `/help` surface and linked from the site
 * footer and the app. NOT tenant-scoped — the same guidance is shown to every
 * Company. Keyed by a stable {@see $slug} so the set of sections is extensible
 * from the admin without schema changes.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $summary
 * @property bool $is_published
 * @property int $sort_order
 * @property-read Collection<int, HelpArticle> $articles
 */
class HelpCategory extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'title',
        'summary',
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
     * The articles in this section, ordered for display.
     *
     * @return HasMany<HelpArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(HelpArticle::class)
            ->orderBy('sort_order')
            ->orderBy('title');
    }

    /**
     * The published articles in this section that have content, ordered for the
     * public Knowledge Base. Drafts and empty articles are excluded.
     *
     * @return HasMany<HelpArticle, $this>
     */
    public function publishedArticles(): HasMany
    {
        return $this->articles()
            ->where('is_published', true)
            ->whereNotNull('body')
            ->where('body', '!=', '');
    }

    /**
     * The published categories that have at least one published, non-empty
     * article, eager-loading those articles, ordered for the public Knowledge
     * Base hub. Empty or draft sections are excluded.
     *
     * @return Collection<int, self>
     */
    public static function published(): Collection
    {
        return static::query()
            ->where('is_published', true)
            ->whereHas('publishedArticles')
            ->with('publishedArticles')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }

    /**
     * Every category for the admin management surface, with its full (including
     * draft) article set eager-loaded and a count, ordered consistently.
     *
     * @return Collection<int, self>
     */
    public static function forManagement(): Collection
    {
        return static::query()
            ->withCount('articles')
            ->with('articles')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }
}
