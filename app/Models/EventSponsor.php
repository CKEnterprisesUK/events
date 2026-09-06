<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EventSponsorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single sponsor of an Event: a logo image plus optional public-store-page
 * details (name, website, bio), an `on_ticket` flag choosing whether the logo
 * is printed on the ticket PDF, and a `sort_order` driving the display order on
 * the public event/storefront page. (Sponsors management)
 *
 * Replaces the two fixed top/bottom sponsor slots that used to live as columns
 * on {@see Event}. An Event may have any number of sponsors for its public
 * page; the application caps how many may be flagged `on_ticket`.
 *
 * Sponsors are Company-owned: the {@see BelongsToCompany} trait registers the
 * global `company_id` tenant scope and auto-fills `company_id` from the
 * resolved tenant on create, so a sponsor always belongs to the active Company
 * and cross-Company rows never match.
 *
 * @property int $id
 * @property int $company_id
 * @property int $event_id
 * @property string $image_path
 * @property string|null $name
 * @property string|null $website_url
 * @property string|null $bio
 * @property bool $on_ticket
 * @property int $sort_order
 */
class EventSponsor extends Model
{
    /** @use HasFactory<EventSponsorFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'event_id',
        'image_path',
        'name',
        'website_url',
        'bio',
        'on_ticket',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'on_ticket' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_ticket' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The Event this sponsor belongs to.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
