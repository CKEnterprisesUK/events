<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Services\Events\CapacityComparison;
use App\Services\Events\ChecklistItem;
use App\Services\Events\EventReadinessReport;

/**
 * Computes the publish-readiness checklist and capacity comparison for a single
 * Event, so the Manage_Event_Page can show — at a glance — what is done and what
 * still stands between the Event and being published.
 *
 * The checklist lists items in a stable order: event name, start date, venue,
 * at-least-one ticket type, shared-pool overall capacity, and payments.
 * The blocking items (start date, ticket type, shared-pool capacity, and
 * payments) are derived from {@see Event::publishBlockers()}, which is the
 * single source of truth the publish controller enforces against — so the
 * presentation and the enforcement can never drift apart. The name and venue
 * items are advisory only and never block publishing. (Requirements 2.1–2.4)
 *
 * This is a stateless service returning immutable value objects the Blade view
 * renders, mirroring the {@see \App\Services\Onboarding\OnboardingChecklist}
 * precedent. It reads only the Event's own persisted state, so the checklist
 * reflects reality without any separate progress flags to keep in sync.
 */
class EventReadiness
{
    /**
     * Build the ordered readiness checklist for the given Event. The blocking
     * items reuse {@see Event::publishBlockers()} so they match publish
     * enforcement exactly. (Requirements 2.1, 2.2, 2.3, 2.4)
     */
    public function checklist(Event $event): EventReadinessReport
    {
        // Single source of truth for the blocking items; call once and reuse.
        $blockers = $event->publishBlockers();

        return new EventReadinessReport([
            new ChecklistItem(
                key: 'name',
                label: 'Event name',
                satisfied: $this->filled($event->name),
                blocking: false,
            ),
            new ChecklistItem(
                key: 'starts_at',
                label: 'Start date',
                satisfied: ! isset($blockers['starts_at']),
                blocking: true,
            ),
            new ChecklistItem(
                key: 'venue',
                label: 'Venue',
                satisfied: $this->filled($event->venue),
                blocking: false,
            ),
            new ChecklistItem(
                key: 'ticket_types',
                label: 'Ticket type',
                satisfied: ! isset($blockers['ticket_types']),
                blocking: true,
            ),
            new ChecklistItem(
                key: 'shared_pool_capacity',
                label: 'Overall capacity for shared pool',
                satisfied: ! isset($blockers['shared_pool_capacity']),
                blocking: true,
            ),
            new ChecklistItem(
                key: 'payments',
                label: 'Stripe ready for paid tickets',
                satisfied: ! isset($blockers['payments']),
                blocking: true,
            ),
        ]);
    }

    /**
     * Compare the Event's optional overall capacity ceiling against the sum of
     * its ticket-type capacities. (Requirements 3.2, 3.3)
     */
    public function capacity(Event $event): CapacityComparison
    {
        return new CapacityComparison(
            eventCapacity: $event->capacity,
            typesSum: (int) $event->ticketTypes()->sum('capacity'),
        );
    }

    /**
     * Whether a nullable string value is present (non-null and not just
     * whitespace). Used for the name and venue readiness items.
     */
    private function filled(?string $v): bool
    {
        return $v !== null && trim($v) !== '';
    }
}
