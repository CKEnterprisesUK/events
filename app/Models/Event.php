<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An Event is a scheduled occurrence for which a Company sells tickets,
 * addressed publicly at `events.domain/{company-slug}/{event-id}/`.
 *
 * Events are Company-owned: the {@see BelongsToCompany} trait registers the
 * global `company_id` tenant scope and auto-fills `company_id` from the
 * resolved tenant on create, so an Event always belongs to the active Company
 * and cross-Company rows never match. (Requirement 5.1)
 *
 * `capacity` is the optional overall Event capacity (NULL = unlimited).
 * `is_published` gates public availability: a published Event's page is served
 * to Customers (Requirement 5.4) while an unpublished Event is not viewable or
 * purchasable (Requirement 5.5). The branding columns are per-Event overrides
 * of the Company-level branding. (Requirement 7.5)
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $description
 * @property string|null $venue
 * @property Carbon|null $starts_at
 * @property int|null $capacity
 * @property bool $is_published
 * @property string|null $primary_colour
 * @property string|null $logo_path
 * @property array|null $ticket_field_defs
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use BelongsToCompany, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'description',
        'venue',
        'starts_at',
        'capacity',
        'is_published',
        'primary_colour',
        'logo_path',
        'ticket_field_defs',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_published' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'capacity' => 'integer',
            'is_published' => 'boolean',
            'ticket_field_defs' => 'array',
        ];
    }

    /**
     * The Ticket_Types offered for this Event. (Requirement 6.2)
     *
     * @return HasMany<TicketType, $this>
     */
    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    /**
     * The Orders placed against this Event.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Whether this Event is published and therefore available to Customers at
     * its public page. (Requirements 5.4, 5.5)
     */
    public function isPublished(): bool
    {
        return (bool) $this->is_published;
    }

    /**
     * Publish the Event, making its page available to Customers. (5.4)
     */
    public function publish(): bool
    {
        $this->is_published = true;

        return $this->save();
    }

    /**
     * Unpublish the Event, blocking Customer view/purchase. (5.5)
     */
    public function unpublish(): bool
    {
        $this->is_published = false;

        return $this->save();
    }
}
