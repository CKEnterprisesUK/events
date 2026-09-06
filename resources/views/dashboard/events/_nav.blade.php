{{--
    Per-event section sidebar. Renders a vertical list of links to the dedicated
    manage screens, highlighting the active one and flagging any section that
    owns an unmet publish-blocking item.

    Expects:
      $event  — the Event being managed.
      $active — the current section key ('overview'|'location'|'tickets'
                |'share'|'report'|'orders').
      $readiness — App\Services\Events\EventReadinessReport (->items()).

    Section flags mirror the setup checklist: a section shows a red ✗ if it owns
    any unmet blocking item, or a green ✓ once all its blocking items are met.
    Advisory-only sections (share/report/orders) never carry a flag.
--}}
@php
    $active = $active ?? 'overview';

    // Which manage screen fixes each checklist item. Venue now lives on the
    // Location screen (moved off Overview), so it flags there.
    $itemSection = [
        'name' => 'overview',
        'starts_at' => 'overview',
        'venue' => 'location',
        'ticket_types' => 'tickets',
        'shared_pool_capacity' => 'tickets',
        'capacity' => 'tickets',
    ];

    // Reduce the readiness items to a per-section status: 'todo' if the section
    // owns any unmet blocking item, else 'done' if it owns any blocking item at
    // all. Sections with no blocking items stay unflagged.
    $sectionStatus = [];
    foreach (($readiness?->items() ?? []) as $item) {
        if (! $item->blocking) {
            continue;
        }
        $section = $itemSection[$item->key] ?? null;
        if ($section === null) {
            continue;
        }
        if (($sectionStatus[$section] ?? null) === 'todo') {
            continue; // once todo, stays todo
        }
        $sectionStatus[$section] = $item->satisfied ? 'done' : 'todo';
    }

    $links = [
        ['key' => 'overview', 'label' => 'Overview', 'icon' => '📋', 'url' => route('dashboard.events.show', $event)],
        ['key' => 'location', 'label' => 'Where',    'icon' => '📍', 'url' => route('dashboard.events.location', $event)],
        ['key' => 'tickets',  'label' => 'Tickets',  'icon' => '🎟️', 'url' => route('dashboard.events.tickets', $event)],
        // Branding + Sponsors manage the event's presentation, gated on the
        // settings permission (matching the old top-right Branding action).
        ...(auth()->user()?->can('settings') ? [
            ['key' => 'branding', 'label' => 'Branding', 'icon' => '🎨', 'url' => route('dashboard.branding.event.edit', $event)],
            ['key' => 'sponsors', 'label' => 'Sponsors', 'icon' => '⭐', 'url' => route('dashboard.events.sponsors', $event)],
        ] : []),
        ['key' => 'share',    'label' => 'Share',    'icon' => '🔗', 'url' => route('dashboard.events.share', $event)],
        ['key' => 'report',   'label' => 'Report',   'icon' => '📈', 'url' => route('dashboard.events.report', $event)],
        ['key' => 'orders',   'label' => 'Orders',   'icon' => '🧾', 'url' => route('dashboard.events.orders', $event)],
        ['key' => 'history',  'label' => 'History',  'icon' => '🕓', 'url' => route('dashboard.events.history', $event)],
    ];
@endphp

<nav class="section-nav" aria-label="Event sections">
    @foreach ($links as $link)
        @php $status = $sectionStatus[$link['key']] ?? null; @endphp
        <a class="section-nav__link {{ $active === $link['key'] ? 'is-active' : '' }}"
           href="{{ $link['url'] }}"
           @if ($active === $link['key']) aria-current="page" @endif>
            <span class="section-nav__ico" aria-hidden="true">{{ $link['icon'] }}</span>
            <span>{{ $link['label'] }}</span>
            @if ($status === 'todo')
                <span class="section-nav__flag section-nav__flag--todo" aria-hidden="true">&times;</span>
                <span class="sr-only">(needs attention)</span>
            @elseif ($status === 'done')
                <span class="section-nav__flag section-nav__flag--done" aria-hidden="true">&check;</span>
                <span class="sr-only">(complete)</span>
            @endif
        </a>
    @endforeach
</nav>
