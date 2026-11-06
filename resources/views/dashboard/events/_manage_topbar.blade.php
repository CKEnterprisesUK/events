{{--
    Manage-event top bar — the primary action surface for a single event's
    management screens. Sits in the `.page-head` row of `layouts/event.blade.php`,
    replacing the old "All events" link cluster.

    Left:  event name + status pill (Published / Draft / Cancelled).
    Right: Duplicate + Publish (or Unpublish when live). Cancel / Delete live on
    their own page (dashboard.events.cancellation), linked from the sidebar.

    The Publish/Unpublish forms moved here from `_publish_card.blade.php` (the
    card is now the checklist only). Publish posts to the existing
    `dashboard.events.publish` route; `EventController::publish()` still gates on
    `publishBlockers()` and flashes `publish_errors`, so this bar only mirrors
    that state — it never changes the server-side contract.

    Deletion vs. cancellation: both live on their own page
    (`dashboard.events.cancellation`, linked quietly from the bottom of the event
    sidebar) rather than as a prominent button here. An event with no confirmed
    bookings can be deleted; one that has taken bookings can only be cancelled.

    Expects:
      $event          — the Event being managed.
      $allRequiredMet — bool computed once in the layout from $readiness->items();
                        disables Publish when required blockers remain.

    Rendered only for users who pass the ACTION_MANAGE_EVENTS gate ('events'),
    the same gate every manage screen already enforces.
--}}
@once
    @push('head')
    <style>
        .pill--cancelled { background: #fef2f2; color: #b91c1c; }
        .notice { border-radius: 0.6rem; padding: 0.9rem 1.1rem; font-size: 0.9rem; line-height: 1.5; }
        .notice--warning { background: #fef6e7; border: 1px solid #f5d58a; color: #7a5200; }
        .notice--warning strong { display: block; margin-bottom: 0.25rem; }
        .btn-danger-outline { background: transparent; color: #b91c1c; box-shadow: inset 0 0 0 1px #fecaca; }
        .btn-danger-outline:hover { background: #fef2f2; color: #991b1b; }
    </style>
    @endpush
@endonce

@can('events')
    @php
        $isCancelled = $event->isCancelled();
    @endphp

    <div class="manage-topbar">
        <div class="manage-topbar__title">
            <h1>{{ $event->name }}</h1>
            @if ($isCancelled)
                <span class="pill pill--cancelled">Cancelled</span>
            @else
                <span class="pill {{ $event->isPublished() ? 'pill--live' : 'pill--draft' }}">
                    {{ $event->isPublished() ? 'Published' : 'Draft' }}
                </span>
            @endif
        </div>
        <div class="manage-topbar__actions">
            {{-- Duplicate into a fresh draft. Non-destructive (no bookings are
                 copied), so no confirmation modal — it just lands the organiser
                 on the new draft's overview. Available in every state, including
                 a cancelled event (handy for re-running next year). --}}
            <form method="POST" action="{{ route('dashboard.events.duplicate', $event) }}">
                @csrf
                <button type="submit" class="btn btn-outline">Duplicate</button>
            </form>

            @unless ($isCancelled)
                @if ($event->isPublished())
                    <form method="POST" action="{{ route('dashboard.events.unpublish', $event) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline">Unpublish</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('dashboard.events.publish', $event) }}">
                        @csrf
                        <button type="submit" class="btn" @disabled(! $allRequiredMet)>Publish event</button>
                    </form>
                @endif
            @endunless
        </div>
    </div>

    {{-- A cancelled event keeps its bookings; remind the organiser of the
         out-of-band follow-up (customer contact + refunds via support). Kept
         compact: one line of guidance plus two direct actions, not a tall
         alarm banner. (UI: cancelled event state) --}}
    @if ($isCancelled)
        <div class="event-cancelled" role="note">
            <div class="event-cancelled__text">
                <strong>Event cancelled</strong>
                <span>Bookings have been retained. Refund affected customers from the Orders page.</span>
            </div>
            <div class="event-cancelled__actions">
                <a class="btn btn-outline btn-sm" href="{{ route('dashboard.customers.index', ['event' => $event->id]) }}">View customers</a>
                <a class="btn btn-outline btn-sm" href="{{ route('dashboard.events.cancellation', $event) }}">Refund guidance</a>
            </div>
        </div>
    @endif
@endcan
