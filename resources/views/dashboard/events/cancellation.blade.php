{{--
    Cancel / Delete screen for a single event. Kept off the top bar on purpose:
    cancelling is rare and consequential, so it gets its own page that explains
    what happens and how refunds work before offering the action.

    Expects (from EventController::cancellation()):
      $event       — the Event being managed.
      $hasBookings — bool: confirmed bookings exist (cancel) or not (delete).
      $report      — EventReport for the event's current figures.
      $refunds     — array{orders:int, amount_minor:int} refunds issued so far.
--}}
@extends('layouts.event')

@section('active_section', 'cancellation')
@section('hide_hero', '1')

@section('section')
    @php
        $currency = $event->company->currency ?? 'GBP';
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $sym = $symbols[$currency] ?? '';
        $money = fn (int $minor) => $sym . number_format($minor / 100, 2);
        $isCancelled = $event->isCancelled();
    @endphp

    <div class="panel">
        <div class="panel__head">
            <h2>
                @if ($isCancelled)
                    Event cancelled
                @elseif ($hasBookings)
                    Cancel this event
                @else
                    Delete this event
                @endif
            </h2>
        </div>

        <div style="padding: 1rem 1.25rem;">
            @if ($hasBookings || $isCancelled)
                <h3>What cancelling does</h3>
                <ul>
                    <li>The event is unpublished and removed from your public page, so no new tickets can be bought.</li>
                    <li>Every existing order is kept, so you still have a full record of who booked and what they paid.</li>
                    <li>Nothing is refunded automatically and customers are not emailed. You refund them yourself, as described below.</li>
                </ul>

                <h3>How to refund your customers</h3>
                <ol>
                    <li>Open <a href="{{ route('dashboard.orders.index', ['event' => $event->id, 'status' => 'paid']) }}">the paid orders for this event</a>.</li>
                    <li>Open an order and choose <strong>Refund</strong> to return the full amount, or <strong>Partial refund</strong> to return part of it.</li>
                    <li>The refund is issued through Stripe from your own account. The customer gets a confirmation email, and the money normally reaches them within 7 working days.</li>
                    <li>A fully refunded order voids its tickets automatically.</li>
                </ol>
                <p class="hint">
                    Need to contact people first? Everyone who booked is listed under
                    <a href="{{ route('dashboard.customers.index', ['event' => $event->id]) }}">Customers</a>.
                    Only roles with refund permission see the Refund buttons.
                </p>

                <div class="summary-row" style="margin: 1rem 0;">
                    <div class="metric-card">
                        <span class="metric-card__label">Orders still confirmed</span>
                        <span class="metric-card__value">{{ number_format($report->confirmedOrders) }}</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-card__label">Tickets sold</span>
                        <span class="metric-card__value">{{ number_format($report->ticketsSold) }}</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-card__label">Collected</span>
                        <span class="metric-card__value">{{ $money($report->grossRevenueMinor) }}</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-card__label">Refunded so far</span>
                        <span class="metric-card__value">{{ $money($refunds['amount_minor']) }}</span>
                        <span class="metric-card__sub">{{ number_format($refunds['orders']) }} {{ \Illuminate\Support\Str::plural('order', $refunds['orders']) }}</span>
                    </div>
                </div>

                @if ($isCancelled)
                    <p class="status" role="status">
                        This event was cancelled{{ $event->cancelled_at ? ' on '.$event->cancelled_at->format('j M Y') : '' }}.
                    </p>
                @else
                    <form method="POST" action="{{ route('dashboard.events.cancel', $event) }}">
                        @csrf
                        <div class="notice notice--warning" role="note">
                            <strong>You’ll need to follow up.</strong>
                            Cancelling can’t be switched off again. To run the event later, duplicate it into a new draft.
                        </div>
                        <label style="display:flex; gap:.5rem; align-items:flex-start; margin: 1rem 0;">
                            <input type="checkbox" required>
                            <span>I understand that “{{ $event->name }}” will stop selling and that I need to refund customers myself.</span>
                        </label>
                        <button type="submit" class="btn btn-danger">Cancel event</button>
                        <a class="btn btn-outline" href="{{ route('dashboard.events.show', $event) }}">Keep event</a>
                    </form>
                @endif
            @else
                <p>
                    “{{ $event->name }}” has no bookings, so it can be deleted permanently. Its ticket types
                    and sponsors are removed with it. This can’t be undone.
                </p>
                <form method="POST" action="{{ route('dashboard.events.destroy', $event) }}">
                    @csrf
                    @method('DELETE')
                    <label style="display:flex; gap:.5rem; align-items:flex-start; margin: 1rem 0;">
                        <input type="checkbox" required>
                        <span>I understand this permanently deletes “{{ $event->name }}”.</span>
                    </label>
                    <button type="submit" class="btn btn-danger">Delete event</button>
                    <a class="btn btn-outline" href="{{ route('dashboard.events.show', $event) }}">Keep event</a>
                </form>
            @endif
        </div>
    </div>
@endsection
