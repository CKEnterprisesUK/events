{{--
    Publish checklist card — the single, at-a-glance source of truth for what's
    done and what still blocks publishing, plus the Publish/Unpublish control.
    Pinned in the right column of the manage-event layout.

    Expects:
      $event     — the Event being managed.
      $readiness — App\Services\Events\EventReadinessReport (->items()).

    Each ChecklistItem has ->key, ->label, ->satisfied, ->blocking. We map each
    key to the tab that fixes it so an unmet item links straight there via the
    [data-tab-link] handler in _tabs.blade.php. Counting/mapping lives here
    (presentation) so the EventReadiness service contract stays untouched.
--}}
@php
    // Which tab does each checklist item live on?
    $itemTab = [
        'name' => 'overview',
        'starts_at' => 'overview',
        'venue' => 'overview',
        'ticket_types' => 'ticket-types',
        'shared_pool_capacity' => 'ticket-types',
        'capacity' => 'ticket-types',
    ];

    $items = $readiness->items();

    // Blocking items decide publishability; count the outstanding ones for the
    // progress line and to mirror the button's disabled state.
    $blockingItems = array_values(array_filter($items, fn ($i) => $i->blocking));
    $blockingTotal = count($blockingItems);
    $blockingDone = count(array_filter($blockingItems, fn ($i) => $i->satisfied));
    $allRequiredMet = $blockingDone === $blockingTotal;
@endphp

<div class="publish-card">
    <div class="publish-card__head">
        <h2>Setup checklist</h2>
        <span class="publish-card__progress">{{ $blockingDone }}/{{ $blockingTotal }} required</span>
    </div>

    <ul class="checklist">
        @foreach ($items as $item)
            @php $tab = $itemTab[$item->key] ?? null; @endphp
            <li class="checklist__item {{ $item->satisfied ? 'checklist__item--done' : '' }}">
                @if ($item->satisfied)
                    <span class="checklist__icon checklist__icon--done" aria-hidden="true">&check;</span>
                    <span class="sr-only">Done:</span>
                @elseif ($item->blocking)
                    <span class="checklist__icon checklist__icon--todo" aria-hidden="true">&times;</span>
                    <span class="sr-only">Needs attention:</span>
                @else
                    <span class="checklist__icon checklist__icon--optional" aria-hidden="true">&ndash;</span>
                    <span class="sr-only">Optional:</span>
                @endif

                <span class="checklist__body">
                    <span class="checklist__label">{{ $item->label }}</span>
                    @if (! $item->satisfied)
                        <span class="checklist__meta">
                            @if ($item->blocking)<span class="checklist__req">Required to publish.</span> @endif
                            @if ($item->key === 'payments')
                                {{-- Payments live on their own Owner-gated page,
                                     not a tab; link out to it. --}}
                                @can('stripe')<a href="{{ route('dashboard.stripe.status') }}">Set up payments</a>@endcan
                            @elseif ($tab)
                                <a href="#tab-{{ $tab }}" data-tab-link="{{ $tab }}">Fix</a>
                            @endif
                        </span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>

    <div class="publish-card__foot">
        @if ($event->isPublished())
            <form method="POST" action="{{ route('dashboard.events.unpublish', $event) }}">
                @csrf
                <button type="submit" class="btn btn-outline">Unpublish</button>
            </form>
            <p class="publish-card__note">&check; This event is live.</p>
        @else
            <form method="POST" action="{{ route('dashboard.events.publish', $event) }}">
                @csrf
                <button type="submit" class="btn" @disabled(! $allRequiredMet)>Publish event</button>
            </form>
            @if ($allRequiredMet)
                <p class="publish-card__hint">Everything's ready — publish when you are.</p>
            @else
                <p class="publish-card__hint">Complete the {{ $blockingTotal - $blockingDone }} required {{ \Illuminate\Support\Str::plural('item', $blockingTotal - $blockingDone) }} above to publish.</p>
            @endif
        @endif
    </div>
</div>
