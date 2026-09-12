{{--
    Inline event-setup checklist for the Overview page. Replaces the old pinned
    right-column card. Sits within the Overview content and collapses to a
    single "Event setup complete" line once every REQUIRED item is satisfied,
    which the user can expand again.

    Expects:
      $event     — the Event being managed.
      $readiness — EventReadinessReport (->items()).

    Distinguishes required (blocking) from optional (advisory) items. Progress
    counts REQUIRED items only, so it never says "4/4 required" while an
    advisory item still shows incomplete.
--}}
@php
    $items = $readiness->items();

    $itemRoute = [
        'name' => ['route' => 'dashboard.events.show', 'label' => 'Add name'],
        'starts_at' => ['route' => 'dashboard.events.show', 'label' => 'Add start date'],
        'venue' => ['route' => 'dashboard.events.location', 'label' => 'Add venue'],
        'ticket_types' => ['route' => 'dashboard.events.tickets', 'label' => 'Add ticket type'],
        'shared_pool_capacity' => ['route' => 'dashboard.events.tickets', 'label' => 'Set capacity'],
    ];

    $blocking = array_values(array_filter($items, fn ($i) => $i->blocking));
    $blockingTotal = count($blocking);
    $blockingDone = count(array_filter($blocking, fn ($i) => $i->satisfied));
    $allRequiredMet = $blockingDone === $blockingTotal;
@endphp

<details class="setup-checklist" @unless ($allRequiredMet) open @endunless>
    <summary class="setup-checklist__summary">
        @if ($allRequiredMet)
            <span class="setup-checklist__done">
                <span class="setup-checklist__tick" aria-hidden="true"><x-icon name="check" /></span>
                Event setup complete
            </span>
        @else
            <span class="setup-checklist__title">Event setup</span>
            <span class="setup-checklist__progress">{{ $blockingDone }} of {{ $blockingTotal }} required complete</span>
        @endif
        <x-icon name="chevron" class="setup-checklist__chev" aria-hidden="true" />
    </summary>

    <ul class="setup-list">
        @foreach ($items as $item)
            @php $fix = $itemRoute[$item->key] ?? null; @endphp
            <li class="setup-list__item {{ $item->satisfied ? 'is-done' : '' }}">
                @if ($item->satisfied)
                    <span class="setup-list__icon setup-list__icon--done" aria-hidden="true"><x-icon name="check" /></span>
                    <span class="sr-only">Done:</span>
                @elseif ($item->blocking)
                    <span class="setup-list__icon setup-list__icon--todo" aria-hidden="true">!</span>
                    <span class="sr-only">Needs attention:</span>
                @else
                    <span class="setup-list__icon setup-list__icon--optional" aria-hidden="true"><x-icon name="dash" /></span>
                    <span class="sr-only">Optional:</span>
                @endif

                <span class="setup-list__label">
                    {{ $item->label }}
                    @unless ($item->blocking)<span class="setup-list__tag">optional</span>@endunless
                </span>

                @unless ($item->satisfied)
                    <span class="setup-list__action">
                        @if ($item->key === 'payments')
                            @can('stripe_setup')<a href="{{ route('dashboard.stripe.status') }}">Set up payments →</a>@endcan
                        @elseif ($fix)
                            <a href="{{ route($fix['route'], $event) }}">{{ $fix['label'] }} →</a>
                        @endif
                    </span>
                @endunless
            </li>
        @endforeach
    </ul>
</details>
