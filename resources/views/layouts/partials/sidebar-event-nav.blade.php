{{--
    Event-context primary navigation.

    Replaces the organisation nav in the main dark sidebar (#appSidebar) while
    the user is managing a single event, so the app never shows two navigation
    systems at once. The per-event sections that used to live in a second
    floating column (dashboard/events/_nav.blade.php) now live here, grouped:

        ← All events
        EVENT NAME
        [status badge]

        EVENT        Overview / Details / Location / Tickets / Branding / Sponsors
        SALES        Orders / Customers / Reports
        PROMOTION    Share
        OPERATIONS   Scan tickets
        OTHER        History
        ────────────
        Company name
        ← Back to organisation

    Expects (supplied by layouts/dashboard.blade.php):
      $event      — the Event being managed.
      $readiness  — EventReadinessReport (->items()), for the per-section flags.
      $activeSection — current section key.
      $company    — the acting Company (for the "back to organisation" footer).
      $navActive  — route matcher helper.
--}}
@php
    $activeSection = $activeSection ?? 'overview';

    // Per-section readiness status, mirroring the old _nav.blade.php logic:
    //   'todo' — the section owns an unmet BLOCKING item;
    //   'done' — the section owns items and ALL of them are satisfied.
    $itemSection = [
        'name' => 'overview',
        'starts_at' => 'details',
        'venue' => 'location',
        'ticket_types' => 'tickets',
        'shared_pool_capacity' => 'tickets',
    ];

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

    $canManageBranding = auth()->user()?->can('settings');

    // Grouped nav model. Each group => list of [key, label, icon, url, gate?].
    $groups = [
        'Event' => array_filter([
            ['key' => 'overview', 'label' => 'Overview', 'icon' => 'overview', 'url' => route('dashboard.events.show', $event)],
            ['key' => 'details',  'label' => 'Details',  'icon' => 'events',   'url' => route('dashboard.events.show', $event) . '#event-details'],
            ['key' => 'location', 'label' => 'Location', 'icon' => 'location', 'url' => route('dashboard.events.location', $event)],
            ['key' => 'tickets',  'label' => 'Tickets',  'icon' => 'tickets',  'url' => route('dashboard.events.tickets', $event)],
            $canManageBranding ? ['key' => 'branding', 'label' => 'Branding', 'icon' => 'branding', 'url' => route('dashboard.branding.event.edit', $event)] : null,
            $canManageBranding ? ['key' => 'sponsors', 'label' => 'Sponsors', 'icon' => 'sponsors', 'url' => route('dashboard.events.sponsors', $event)] : null,
        ]),
        'Sales' => [
            ['key' => 'orders',    'label' => 'Orders',    'icon' => 'orders',    'url' => route('dashboard.events.orders', $event)],
            ['key' => 'questions', 'label' => 'Questions', 'icon' => 'tickets',   'url' => route('dashboard.events.questions', $event)],
            ['key' => 'report',    'label' => 'Reports',   'icon' => 'reports',   'url' => route('dashboard.events.report', $event)],
        ],
        'Promotion' => [
            ['key' => 'share', 'label' => 'Share', 'icon' => 'share', 'url' => route('dashboard.events.share', $event)],
        ],
        'Other' => [
            ['key' => 'history', 'label' => 'History', 'icon' => 'history', 'url' => route('dashboard.events.history', $event)],
        ],
    ];

    // Event status label + modifier for the badge under the event name.
    if ($event->isCancelled()) {
        $statusLabel = 'Cancelled';
        $statusMod = 'cancelled';
    } elseif ($event->isPublished()) {
        $statusLabel = 'Published';
        $statusMod = 'live';
    } else {
        $statusLabel = 'Draft';
        $statusMod = 'draft';
    }
@endphp

<a class="event-nav__back" href="{{ route('dashboard.events.index') }}">
    <x-icon name="chevron" class="event-nav__back-ico" /> All events
</a>

<div class="event-nav__title">
    <span class="event-nav__name" title="{{ $event->name }}">{{ $event->name }}</span>
    <span class="pill pill--{{ $statusMod }} event-nav__status">{{ $statusLabel }}</span>
</div>

@foreach ($groups as $groupLabel => $links)
    @if (count($links) > 0)
        <p class="nav-section">{{ $groupLabel }}</p>
        @foreach ($links as $link)
            @php $status = $sectionStatus[$link['key']] ?? null; @endphp
            <a class="nav-link {{ $activeSection === $link['key'] ? 'active' : '' }}"
               href="{{ $link['url'] }}"
               @if ($activeSection === $link['key']) aria-current="page" @endif>
                <x-icon :name="$link['icon']" class="nav-ico" />
                <span class="nav-link__label">{{ $link['label'] }}</span>
                @if ($status === 'todo')
                    <span class="nav-link__flag nav-link__flag--todo" aria-hidden="true"><x-icon name="cross" /></span>
                    <span class="sr-only">(needs attention)</span>
                @elseif ($status === 'done')
                    <span class="nav-link__flag nav-link__flag--done" aria-hidden="true"><x-icon name="check" /></span>
                    <span class="sr-only">(complete)</span>
                @endif
            </a>
        @endforeach
    @endif
@endforeach

<div class="event-nav__foot">
    @if ($company)
        <span class="event-nav__org">{{ $company->name }}</span>
    @endif
    <a class="event-nav__back" href="{{ route('dashboard.events.index') }}">
        <x-icon name="chevron" class="event-nav__back-ico" /> Back to organisation
    </a>
</div>
