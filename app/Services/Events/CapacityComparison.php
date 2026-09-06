<?php

declare(strict_types=1);

namespace App\Services\Events;

/**
 * Compares an Event's optional overall capacity ceiling against the sum of its
 * ticket-type capacities and classifies which one binds. (Requirements 3.2–3.4)
 *
 * This is an immutable value object produced from the Event state. The
 * classification it exposes is advisory: it powers an informational readiness
 * warning only and NEVER blocks publishing an Event.
 */
final class CapacityComparison
{
    public const UNLIMITED = 'unlimited';

    public const EVENT_BINDS = 'event_binds';

    public const TYPES_BIND = 'types_bind';

    public const BALANCED = 'balanced';

    /**
     * @param  ?int  $eventCapacity  the Event's optional overall capacity
     *   ceiling; null means unlimited.
     * @param  int  $typesSum  the sum of the Event's ticket-type capacities.
     */
    public function __construct(
        public readonly ?int $eventCapacity,
        public readonly int $typesSum,
    ) {}

    /**
     * Classify which ceiling binds. (Requirements 3.2, 3.3)
     *
     * - {@see UNLIMITED} when there is no overall capacity ceiling.
     * - {@see EVENT_BINDS} when the overall ceiling is below the ticket-type sum.
     * - {@see TYPES_BIND} when the overall ceiling exceeds the ticket-type sum.
     * - {@see BALANCED} when the two are equal.
     */
    public function state(): string
    {
        if ($this->eventCapacity === null) {
            return self::UNLIMITED;
        }

        if ($this->eventCapacity < $this->typesSum) {
            return self::EVENT_BINDS;
        }

        if ($this->eventCapacity > $this->typesSum) {
            return self::TYPES_BIND;
        }

        return self::BALANCED;
    }

    /**
     * Whether the capacity configuration is "sane" (the ceiling and the
     * ticket-type sum agree or there is no ceiling). (Requirement 3.4)
     *
     * This is purely informational: it drives a non-blocking readiness warning
     * and NEVER prevents an Event from being published.
     */
    public function isSane(): bool
    {
        return match ($this->state()) {
            self::UNLIMITED, self::BALANCED => true,
            default => false,
        };
    }
}
