{{--
    Tickets screen: ticket-type management + complimentary issuance.
    (Requirements 6.1, 6.3, 2.6, 18.1)
--}}
@extends('layouts.event')

@section('active_section', 'tickets')

@section('section')
    @cannot('events')
        {{-- Read-only view for roles that may see availability and prices but
             not change them (e.g. Box_Office). --}}
        @php
            $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
            $currency = $event->company?->currency ?? auth()->user()->company?->currency ?? 'GBP';
            $symbol = $symbols[$currency] ?? '';
        @endphp
        <div class="panel">
            <div class="panel__head"><h2>Ticket availability</h2></div>
            <table class="data-table">
                <thead>
                    <tr><th>Ticket</th><th>Price</th><th>Remaining</th></tr>
                </thead>
                <tbody>
                    @forelse ($ticketTypes as $ticketType)
                        @php $remaining = $ticketType->availabilityFor($eventRemaining); @endphp
                        <tr>
                            <td>{{ $ticketType->name }}</td>
                            <td>{{ $ticketType->isFree() ? 'Free' : $symbol.number_format($ticketType->price_minor / 100, 2) }}</td>
                            <td>{{ $remaining === null ? 'Unlimited' : $remaining }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">No ticket types yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endcannot
    @can('events')
    {{-- Ticket types come first — the main job of this screen. The inline
         accordion (Req 6) replaces the old show/hide table; it derives its
         currency symbol and store/update/destroy routes itself, so it only
         needs the event, the existing types, and the overall remaining. --}}
    @include('dashboard.events._ticket_accordion', [
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
        @if (($heldInCheckout ?? 0) > 0)
            <p class="hint">
                <strong>{{ number_format($heldInCheckout) }}</strong>
                {{ \Illuminate\Support\Str::plural('ticket', $heldInCheckout) }} currently held by customers in checkout.
                Held tickets are taken out of the remaining count shown here until the payment completes or the hold expires,
                so "remaining" can be slightly lower than capacity minus the tickets sold on your Dashboard and Reports.
            </p>
        @endif
        {{-- Advisory comparison of the overall ceiling vs the sum of ticket-type
             capacities. Non-blocking; explains which limit binds first. --}}
        @include('dashboard.events._capacity', ['capacity' => $capacity, 'event' => $event])
    </div>
    @endcan
    {{-- Comp issuance is a secondary, occasional action — clearly separated and
         placed last so it never competes with the primary ticket-type setup. --}}
    <p class="section-divider">Complimentary tickets</p>
    @include('dashboard.events._comp', [
        'event' => $event,
        'ticketTypes' => $ticketTypes,
        'eventRemaining' => $eventRemaining,
    ])
@endsection
