{{--
    Per-event section sidebar. Renders a vertical list of links to the dedicated
    manage screens, highlighting the active one and flagging any section that
    owns an unmet publish-blocking item.

    Expects:
      $event  — the Event being managed.
      $active — the current section key ('overview'|'location'|'tickets'
                |'share'|'report'|'orders').
      $readiness — App\Services\Events\EventReadinessReport (->items()).

    Section flags mirror the setup checklist: a section shows a red cross icon
    (<x-icon name="cross" />) if it owns any unmet blocking item, or a green
    check icon (<x-icon name="check" />) once all its blocking items are met.
    Each flag keeps an adjacent sr-only text label so its meaning is conveyed.
    Advisory-only sections (share/report/orders) never carry a flag.
--}}
@php
    $active = $active ?? 'overview';

    // Which manage screen owns each checklist item — including the advisory
    // (non-blocking) name/venue items, so a section can earn a green tick once
    // everything it owns is done, not only its publish-blocking prerequisites.
    // Venue lives on the Location ("Where") screen.
    $itemSection = [
        'name' => 'overview',
        'starts_at' => 'overview',
        'venue' => 'location',
        'ticket_types' => 'tickets',
        'shared_pool_capacity' => 'tickets',
    ];

    // Reduce the readiness items to a per-section status:
    //   'todo' — the section owns an unmet BLOCKING item (still needs work);
    //   'done' — the section owns at least one item and ALL of them (blocking
    //            and advisory) are satisfied.
    // A section with a satisfied advisory item but an unmet blocking one stays
    // 'todo'; a section whose only items are advisory ticks 'done' once they're
    // filled in. This surfaces more ticks as the organiser completes each area.
    $sectionUnmetBlocking = [];
    $sectionHasItem = [];
    $sectionAllSatisfied = [];
    foreach (($readiness?->items() ?? []) as $item) {
        $section = $itemSection[$item->key] ?? null;
        if ($section === null) {
            continue;
        }
        $sectionHasItem[$section] = true;
        $sectionAllSatisfied[$section] = ($sectionAllSatisfied[$section] ?? true) && $item->satisfied;
        if ($item->blocking && ! $item->satisfied) {
            $sectionUnmetBlocking[$section] = true;
        }
    }

    $sectionStatus = [];
    foreach (array_keys($sectionHasItem) as $section) {
        if (! empty($sectionUnmetBlocking[$section])) {
            $sectionStatus[$section] = 'todo';
        } elseif (! empty($sectionAllSatisfied[$section])) {
            $sectionStatus[$section] = 'done';
        }
    }

    // Each link carries an icon *name* resolved by the <x-icon> component
    // (see resources/views/components/icon.blade.php), replacing the old emoji.
    $links = [
        ['key' => 'overview', 'label' => 'Overview', 'icon' => 'overview', 'url' => route('dashboard.events.show', $event)],
        ['key' => 'location', 'label' => 'Where',    'icon' => 'location', 'url' => route('dashboard.events.location', $event)],
        ['key' => 'tickets',  'label' => 'Tickets',  'icon' => 'tickets',  'url' => route('dashboard.events.tickets', $event)],
        ['key' => 'questions', 'label' => 'Questions', 'icon' => 'tickets', 'url' => route('dashboard.events.questions', $event)],
        // Branding + Sponsors manage the event's presentation, gated on the
        // settings permission (matching the old top-right Branding action).
        ...(auth()->user()?->can('settings') ? [
            ['key' => 'branding', 'label' => 'Branding', 'icon' => 'branding', 'url' => route('dashboard.branding.event.edit', $event)],
            ['key' => 'sponsors', 'label' => 'Sponsors', 'icon' => 'sponsors', 'url' => route('dashboard.events.sponsors', $event)],
        ] : []),
        ['key' => 'share',    'label' => 'Share',    'icon' => 'share',   'url' => route('dashboard.events.share', $event)],
        ['key' => 'report',   'label' => 'Report',   'icon' => 'report',  'url' => route('dashboard.events.report', $event)],
        ['key' => 'orders',   'label' => 'Orders',   'icon' => 'orders',  'url' => route('dashboard.events.orders', $event)],
        ['key' => 'history',  'label' => 'History',  'icon' => 'history', 'url' => route('dashboard.events.history', $event)],
    ];
@endphp

<div class="section-nav__head">
    {{-- Collapse control. Toggles `event-manage--rail` on the `.event-manage`
         container (id="eventManage"); wired up + persisted (key ck.sidebar.section)
         by the bindCollapse JS in task 9.3. --}}
    <button type="button"
            id="sectionNavToggle"
            class="section-nav__toggle"
            aria-expanded="true"
            aria-controls="eventManage"
            aria-label="Collapse navigation">
        <x-icon name="panel-left" />
    </button>
</div>

<nav class="section-nav" aria-label="Event sections">
    @foreach ($links as $link)
        @php $status = $sectionStatus[$link['key']] ?? null; @endphp
        <a class="section-nav__link {{ $active === $link['key'] ? 'is-active' : '' }}"
           href="{{ $link['url'] }}"
           title="{{ $link['label'] }}"
           @if ($active === $link['key']) aria-current="page" @endif>
            <x-icon :name="$link['icon']" class="section-nav__ico" />
            <span>{{ $link['label'] }}</span>
            @if ($status === 'todo')
                <span class="section-nav__flag section-nav__flag--todo">
                    <x-icon name="cross" />
                </span>
                <span class="sr-only">(needs attention)</span>
            @elseif ($status === 'done')
                <span class="section-nav__flag section-nav__flag--done">
                    <x-icon name="check" />
                </span>
                <span class="sr-only">(complete)</span>
            @endif
        </a>
    @endforeach
</nav>
