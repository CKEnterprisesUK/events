{{--
    Manage-event top bar — the primary action surface for a single event's
    management screens. Sits in the `.page-head` row of `layouts/event.blade.php`,
    replacing the old "All events" link cluster.

    Left:  event name + live/draft status pill (the published-state indicator).
    Right: Publish (or Unpublish when live) + "All events".

    The Publish/Unpublish forms moved here from `_publish_card.blade.php` (the
    card is now the checklist only). Publish posts to the existing
    `dashboard.events.publish` route; `EventController::publish()` still gates on
    `publishBlockers()` and flashes `publish_errors`, so this bar only mirrors
    that state — it never changes the server-side contract.

    Expects:
      $event          — the Event being managed.
      $allRequiredMet — bool computed once in the layout from $readiness->items();
                        disables Publish when required blockers remain.

    Rendered only for users who pass the ACTION_MANAGE_EVENTS gate ('events'),
    the same gate every manage screen already enforces.
--}}
@can('events')
    <div class="manage-topbar">
        <div class="manage-topbar__title">
            <h1>{{ $event->name }}</h1>
            <span class="pill {{ $event->isPublished() ? 'pill--live' : 'pill--draft' }}">
                {{ $event->isPublished() ? 'Published' : 'Draft' }}
            </span>
        </div>
        <div class="manage-topbar__actions">
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
            <a class="btn btn-outline" href="{{ route('dashboard.events.index') }}">All events</a>
        </div>
    </div>
@endcan
