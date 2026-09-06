<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Records a Stripe webhook event the Platform has already handled, keyed by the
 * Stripe event id. The UNIQUE index on `stripe_event_id` makes webhook
 * processing idempotent: the first delivery inserts the row and proceeds; a
 * redelivery of the same event id collides on the unique key and is skipped, so
 * `checkout.session.completed` marks an Order paid at most once and creates no
 * additional charge. (Requirements 12.6, 12.7, 19.3)
 *
 * Not Company-owned — Stripe posts to a single fixed Platform endpoint with no
 * company slug, so there is no tenant scope on this table.
 *
 * @property int $id
 * @property string $stripe_event_id
 * @property string $type
 * @property Carbon $processed_at
 */
class ProcessedWebhook extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'stripe_event_id',
        'type',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
