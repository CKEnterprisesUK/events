{{--
    Publish checklist card — the single, at-a-glance source of truth for what's
    done and what still blocks publishing. Pinned in the right column of the
    manage-event layout. The Publish/Unpublish control now lives in the manage
    top bar (`_manage_topbar.blade.php`); this card is the checklist only.

    Expects:
      $event          — the Event being managed.
      $readiness      — App\Services\Events\EventReadinessReport (->items()).
      $allRequiredMet — bool computed once in layouts/event.blade.php from
                        $readiness->items(); shared with the top bar so the
                        card's hint text and the Publish button never disagree.

    Each ChecklistItem has ->key, ->label, ->satisfied, ->blocking. We map each
    key to the dedicated manage screen that fixes it, so an unmet item links
    straight to that screen. Counting/mapping lives here (presentation) so the
    EventReadiness service contract stays untouched.
--}}
@php
    // Which manage screen fixes each checklist item, as a route + the {event}
    // param it needs. Venue now lives on the Location ("Where") screen.
    $itemRoute = [
        'name' => 'dashboard.events.show',
        'starts_at' => 'dashboard.events.show',
        'venue' => 'dashboard.events.location',
        'ticket_types' => 'dashboard.events.tickets',
        'shared_pool_capacity' => 'dashboard.events.tickets',
    ];

    $items = $readiness->items();

    // Blocking items decide publishability; count the outstanding ones for the
    // progress line and the hint text. The button's disabled state lives in the
    // top bar and is driven by the shared $allRequiredMet flag.
    $blockingItems = array_values(array_filter($items, fn ($i) => $i->blocking));
    $blockingTotal = count($blockingItems);
    $blockingDone = count(array_filter($blockingItems, fn ($i) => $i->satisfied));
@endphp

<div class="publish-card">
    <div class="publish-card__head">
        <h2>Setup checklist</h2>
        <span class="publish-card__progress">{{ $blockingDone }}/{{ $blockingTotal }} required</span>
    </div>

    <ul class="checklist">
        @foreach ($items as $item)
            @php $fixRoute = $itemRoute[$item->key] ?? null; @endphp
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
                                {{-- Payments live on their own Owner-gated page. --}}
                                @can('stripe')<a href="{{ route('dashboard.stripe.status') }}">Set up payments</a>@endcan
                            @elseif ($fixRoute)
                                <a href="{{ route($fixRoute, $event) }}">Fix</a>
                            @endif
                        </span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>

    <div class="publish-card__foot">
        @if ($event->isPublished())
            <p class="publish-card__note">&check; This event is live.</p>
        @elseif ($allRequiredMet)
            <p class="publish-card__hint">Everything's ready. Publish from the top of the page when you are.</p>
        @else
            <p class="publish-card__hint">Complete the {{ $blockingTotal - $blockingDone }} required {{ \Illuminate\Support\Str::plural('item', $blockingTotal - $blockingDone) }} above to publish.</p>
        @endif
    </div>
</div>
