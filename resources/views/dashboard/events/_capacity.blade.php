{{-- Capacity explainer + soft, non-blocking warning (Requirements 3.1, 3.2, 3.3).
     Presentational only. Receives:
       $event    — the Event in scope.
       $capacity — App\Services\Events\CapacityComparison with:
                   ->eventCapacity (?int), ->typesSum (int), and ->state().
     The warning is advisory: it never prevents saving, publishing, or
     unpublishing the event. Do not style it as a blocking error. --}}
<div class="panel">
    <div class="panel__head"><h2>Capacity</h2></div>

    {{-- Static explainer (Requirement 3.1). --}}
    <p class="hint">
        The overall capacity is an optional ceiling for the whole event.
        Leave it blank for unlimited.
    </p>
    <p class="hint">
        It works alongside the capacity you set on each ticket type. When both
        are set, whichever is smaller — the overall event capacity or the sum of
        the ticket-type capacities — binds first.
    </p>

    {{-- Non-blocking advisory keyed on the comparison state. --}}
    @switch($capacity->state())
        @case(\App\Services\Events\CapacityComparison::EVENT_BINDS)
            <p class="hint">
                <span class="pill pill--draft">Heads up</span>
                The event capacity ({{ $capacity->eventCapacity }}) is below the
                sum of ticket-type capacities ({{ $capacity->typesSum }}); the
                event capacity will bind first.
            </p>
            @break

        @case(\App\Services\Events\CapacityComparison::TYPES_BIND)
            <p class="hint">
                <span class="pill pill--draft">Heads up</span>
                The sum of ticket-type capacities ({{ $capacity->typesSum }}) is
                below the event capacity ({{ $capacity->eventCapacity }}); the
                ticket-type capacities will bind first.
            </p>
            @break

        @case(\App\Services\Events\CapacityComparison::UNLIMITED)
            <p class="hint muted">No overall ceiling set — capacity is unlimited.</p>
            @break

        @default
            {{-- BALANCED: overall capacity matches the ticket-type sum. --}}
            <p class="hint muted">The event capacity matches the sum of the ticket-type capacities.</p>
    @endswitch
</div>
