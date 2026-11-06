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
                    <li>Every existing order is kept. You can still see all of your customers and their orders after cancelling.</li>
                    <li>Nothing is refunded automatically. Because refunds for a whole event are handled in bulk, cancelling sends a request to our support team, who will contact you by email to arrange them.</li>
                </ul>

                @unless ($isCancelled)
                    @if ($event->isPublished())
                        <div class="notice notice--warning" role="note" style="margin: 1rem 0;">
                            <strong>Just want to pause sales?</strong>
                            You don’t need to cancel. Unpublishing stops new bookings and hides the event, keeps it as a draft,
                            and lets you publish it again whenever you’re ready. Existing tickets stay valid.
                            <form method="POST" action="{{ route('dashboard.events.unpublish', $event) }}" style="margin-top: .75rem;">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-sm">Unpublish instead</button>
                            </form>
                        </div>
                    @endif
                @endunless

                <p class="hint">
                    <a href="{{ route('dashboard.customers.index', ['event' => $event->id]) }}">View everyone who booked</a>
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
                        Our support team has been asked to arrange refunds and will email you. Your customers and orders remain available.
                    </p>
                @else
                    <form method="POST" action="{{ route('dashboard.events.cancel', $event) }}">
                        @csrf
                        <div class="notice notice--warning" role="note">
                            <strong>What happens when you confirm</strong>
                            The event stops selling and a support ticket is raised automatically for your refunds.
                            Cancelling can’t be switched off again. To run the event later, duplicate it into a new draft.
                        </div>
                        <div class="field" style="margin-top: 1rem;">
                            <label for="cancel-note">Anything our team should know? (optional)</label>
                            <textarea id="cancel-note" name="note" rows="3" maxlength="2000"
                                      placeholder="For example: refund everyone in full, or keep the booking fees.">{{ old('note') }}</textarea>
                        </div>
                        <label style="display:flex; gap:.5rem; align-items:flex-start; margin: 1rem 0;">
                            <input type="checkbox" required>
                            <span>I understand that “{{ $event->name }}” will stop selling and that a refund request will be sent to the support team.</span>
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
