{{--
    Manage-event top bar — the primary action surface for a single event's
    management screens. Sits in the `.page-head` row of `layouts/event.blade.php`,
    replacing the old "All events" link cluster.

    Left:  event name + status pill (Published / Draft / Cancelled).
    Right: Publish (or Unpublish when live) + Delete or Cancel + "All events".

    The Publish/Unpublish forms moved here from `_publish_card.blade.php` (the
    card is now the checklist only). Publish posts to the existing
    `dashboard.events.publish` route; `EventController::publish()` still gates on
    `publishBlockers()` and flashes `publish_errors`, so this bar only mirrors
    that state — it never changes the server-side contract.

    Deletion vs. cancellation:
      - An event with no confirmed bookings can be DELETED outright (posts to
        `dashboard.events.destroy`). No customer data is lost because there are
        no bookings.
      - An event that has taken bookings can only be CANCELLED (posts to
        `dashboard.events.cancel`). Its orders are retained; the organiser is
        told to contact affected customers and arrange refunds through support,
        with the full customer list linked from the Customers page.
    Both destructive actions are confirmed via a small modal so a stray click
    can't trigger them.

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

        .confirm-modal { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .confirm-modal[hidden] { display: none; }
        .confirm-modal__backdrop { position: absolute; inset: 0; background: rgba(16, 24, 40, 0.55); }
        .confirm-modal__panel {
            position: relative; background: var(--surface, #fff); border-radius: 0.75rem;
            max-width: 520px; width: 100%; padding: 1.5rem; box-shadow: 0 20px 45px rgba(16, 24, 40, 0.2);
        }
        .confirm-modal__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
        .confirm-modal__head h2 { margin: 0; font-size: 1.15rem; }
        .confirm-modal__close { background: none; border: 0; font-size: 1.5rem; line-height: 1; cursor: pointer; color: var(--muted); }
        .confirm-modal__panel p { margin: 0.9rem 0; }
        .confirm-modal__panel .notice { margin: 0.9rem 0; }
        .confirm-modal__actions { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1.25rem; }
        .confirm-modal__actions form { margin: 0; }
    </style>
    @endpush
@endonce

@can('events')
    @php
        $isCancelled = $event->isCancelled();
        // Whether the event has taken confirmed bookings. Decides between the
        // Cancel action (bookings exist — orders must be retained) and the
        // Delete action (no bookings — safe to remove outright).
        $hasBookings = $event->hasBookings();
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

            @if ($hasBookings)
                @unless ($isCancelled)
                    <button type="button" class="btn btn-danger-outline" data-open-event-cancel>Cancel event</button>
                @endunless
            @else
                <button type="button" class="btn btn-danger-outline" data-open-event-delete>Delete event</button>
            @endif

            <a class="btn btn-outline" href="{{ route('dashboard.events.index') }}">All events</a>
        </div>
    </div>

    {{-- A cancelled event keeps its bookings; remind the organiser of the
         out-of-band follow-up (customer contact + refunds via support). --}}
    @if ($isCancelled)
        <div class="notice notice--warning" role="note" style="margin-top: 1rem;">
            <strong>This event is cancelled.</strong>
            Its bookings are kept so you can follow up. Please contact your
            affected customers and arrange any refunds through
            <a href="{{ route('dashboard.support.create') }}">support</a>. The full,
            up-to-date list of everyone who booked is in
            <a href="{{ route('dashboard.customers.index', ['event' => $event->id]) }}">Customers</a>.
        </div>
    @endif

    {{-- Delete confirmation (only for an event with no bookings). --}}
    @unless ($hasBookings)
        <div class="confirm-modal" data-event-delete-modal hidden>
            <div class="confirm-modal__backdrop" data-close-event-delete></div>
            <div class="confirm-modal__panel" role="dialog" aria-modal="true" aria-labelledby="event-delete-title">
                <div class="confirm-modal__head">
                    <h2 id="event-delete-title">Delete this event?</h2>
                    <button type="button" class="confirm-modal__close" data-close-event-delete aria-label="Close">&times;</button>
                </div>
                <p>
                    “{{ $event->name }}” has no bookings, so it can be deleted
                    permanently. This can’t be undone.
                </p>
                <div class="confirm-modal__actions">
                    <button type="button" class="btn btn-outline" data-close-event-delete>Keep event</button>
                    <form method="POST" action="{{ route('dashboard.events.destroy', $event) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger">Delete event</button>
                    </form>
                </div>
            </div>
        </div>
    @endunless

    {{-- Cancel confirmation (only for an event that has taken bookings, and is
         not already cancelled). --}}
    @if ($hasBookings && ! $isCancelled)
        <div class="confirm-modal" data-event-cancel-modal hidden>
            <div class="confirm-modal__backdrop" data-close-event-cancel></div>
            <div class="confirm-modal__panel" role="dialog" aria-modal="true" aria-labelledby="event-cancel-title">
                <div class="confirm-modal__head">
                    <h2 id="event-cancel-title">Cancel this event?</h2>
                    <button type="button" class="confirm-modal__close" data-close-event-cancel aria-label="Close">&times;</button>
                </div>
                <p>
                    “{{ $event->name }}” has bookings, so it can’t be deleted.
                    Cancelling unpublishes it and stops new bookings, but keeps
                    the existing orders.
                </p>
                <div class="notice notice--warning" role="note">
                    <strong>You’ll need to follow up.</strong>
                    After cancelling, contact your affected customers and arrange
                    any refunds through support. The full, up-to-date list of
                    everyone who booked is on the Customers page.
                </div>
                <div class="confirm-modal__actions">
                    <button type="button" class="btn btn-outline" data-close-event-cancel>Keep event</button>
                    <form method="POST" action="{{ route('dashboard.events.cancel', $event) }}">
                        @csrf
                        <button type="submit" class="btn btn-danger">Cancel event</button>
                    </form>
                </div>
            </div>
        </div>
    @endif

    @once
        @push('scripts')
        <script>
            (function () {
                // Wire a hidden confirm-modal to its open/close triggers. Both the
                // delete and cancel modals share this tiny controller.
                function wireModal(modalSelector, openSelector, closeSelector) {
                    var modal = document.querySelector(modalSelector);
                    if (!modal) return;

                    function open() { modal.hidden = false; }
                    function close() { modal.hidden = true; }

                    document.querySelectorAll(openSelector).forEach(function (el) {
                        el.addEventListener('click', open);
                    });
                    modal.querySelectorAll(closeSelector).forEach(function (el) {
                        el.addEventListener('click', close);
                    });
                    document.addEventListener('keydown', function (e) {
                        if (e.key === 'Escape' && !modal.hidden) { close(); }
                    });
                }

                wireModal('[data-event-delete-modal]', '[data-open-event-delete]', '[data-close-event-delete]');
                wireModal('[data-event-cancel-modal]', '[data-open-event-cancel]', '[data-close-event-cancel]');
            })();
        </script>
        @endpush
    @endonce
@endcan
