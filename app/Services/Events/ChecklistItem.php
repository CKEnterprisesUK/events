<?php

namespace App\Services\Events;

/**
 * One item in an event readiness checklist: a stable key, a human-facing label,
 * whether the item is satisfied by the current event state, and whether it is
 * blocking. A blocking item that is not satisfied prevents the event from being
 * published; non-blocking items are advisory only. (Requirements 2.2, 2.3, 2.4)
 */
final class ChecklistItem
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $satisfied,
        public readonly bool $blocking,
    ) {}
}
