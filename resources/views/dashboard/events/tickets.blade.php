{{--
    Tickets screen: ticket-type management + complimentary issuance.
    (Requirements 6.1, 6.3, 2.6, 18.1)
--}}
@extends('layouts.event')

@section('active_section', 'tickets')

@section('section')
    {{-- Ticket types come first — the main job of this screen. --}}
    @include('dashboard.events._ticket_types', [
        'event' => $event,
        'ticketTypes' => $ticketTypes,
        'eventRemaining' => $eventRemaining,
    ])

    {{-- Overall event capacity (the shared-pool ceiling) lives here, next to
         the ticket types it governs, rather than buried in the Overview form. --}}
    <div class="panel form-panel">
        <div class="panel__head"><h2>Overall capacity</h2></div>
        <form method="POST" action="{{ route('dashboard.events.capacity.update', $event) }}" class="stack">
            @csrf
            @method('PATCH')
            <div class="field">
                <label for="capacity">Total tickets across the whole event <span class="muted">(optional)</span></label>
                <input id="capacity" type="number" name="capacity" min="1" placeholder="Unlimited"
                       value="{{ old('capacity', $event->capacity) }}">
                <p class="hint">A ceiling for the whole event. Leave blank for unlimited. When set, it binds alongside each ticket type's own capacity, and whichever is smaller applies first.</p>
                @error('capacity') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="form-actions">
                <button type="submit" class="btn">Save capacity</button>
            </div>
        </form>
        {{-- Advisory comparison of the overall ceiling vs the sum of ticket-type
             capacities. Non-blocking; explains which limit binds first. --}}
        @include('dashboard.events._capacity', ['capacity' => $capacity, 'event' => $event])
    </div>

    {{-- Comp issuance is a secondary, occasional action — clearly separated and
         placed last so it never competes with the primary ticket-type setup. --}}
    <p class="section-divider">Complimentary tickets</p>
    @include('dashboard.events._comp', [
        'event' => $event,
        'ticketTypes' => $ticketTypes,
        'eventRemaining' => $eventRemaining,
    ])
@endsection
