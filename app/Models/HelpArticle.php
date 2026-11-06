<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A HelpArticle is a single Knowledge Base article (a "how do I…?" / "why
 * did…?" answer) living under a {@see HelpCategory}. Together with its category
 * it is the DB-backed replacement for the hardcoded knowledge base that used to
 * live in `App\Support\HelpCentre`.
 *
 * Platform content owned by Events by CK Enterprises UK, authored by a
 * Super_Admin, published at `/help/{category}/{article}`. NOT tenant-scoped.
 * Keyed by a {@see $slug} that is unique within its category so the public URL
 * is stable and readable.
 *
 * @property int $id
 * @property int $help_category_id
 * @property string $slug
 * @property string $title
 * @property string|null $body
 * @property bool $is_published
 * @property int $sort_order
 * @property-read HelpCategory $category
 */
class HelpArticle extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'help_category_id',
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
     * The section this article belongs to.
     *
     * @return BelongsTo<HelpCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(HelpCategory::class, 'help_category_id');
    }

    /**
     * Whether this article has authored content to show publicly.
     */
    public function hasBody(): bool
    {
        return filled($this->body);
    }

    /**
     * Whether this article is live on the public Knowledge Base (published and
     * with content).
     */
    public function isLive(): bool
    {
        return $this->is_published && $this->hasBody();
    }
}
