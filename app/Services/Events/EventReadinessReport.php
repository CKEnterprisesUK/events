<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Services\Events\ChecklistItem;

/**
 * The computed publish-readiness report for an Event: the ordered checklist
 * items the show page renders so the Owner can see, at a glance, what is done
 * and what still blocks publishing. Immutable — produced by the EventReadiness
 * service.
 */
final class EventReadinessReport
{
    /**
     * @param  list<ChecklistItem>  $items
     */
    public function __construct(public readonly array $items) {}

    /**
     * The ordered readiness checklist items for the view to iterate.
     *
     * @return list<ChecklistItem>
     */
    public function items(): array
    {
        return $this->items;
    }
}
