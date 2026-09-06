@extends('layouts.dashboard')

@section('title', $event->name)

@section('content')
    @php
        // Map the readiness report to a per-tab status for the tab strip: a tab
        // is 'todo' if it owns any unmet BLOCKING item, else 'done' if it owns
        // any blocking item at all (all satisfied), else no glyph (advisory-only
        // tabs like Location/Share/Report/Orders). Mirrors _publish_card's
        // itemTab map so the strip and the checklist never disagree.
        $itemTab = [
            'name' => 'overview', 'starts_at' => 'overview', 'venue' => 'overview',
            'ticket_types' => 'ticket-types', 'shared_pool_capacity' => 'ticket-types', 'capacity' => 'ticket-types',
        ];
        $tabStatus = [];
        foreach ($readiness->items() as $item) {
            if (! $item->blocking) {
                continue;
            }
            $tab = $itemTab[$item->key] ?? null;
            if ($tab === null) {
                continue;
            }
            // Once a tab is 'todo' it stays 'todo'; otherwise mark it 'done'.
            if (($tabStatus[$tab] ?? null) === 'todo') {
                continue;
            }
            $tabStatus[$tab] = $item->satisfied ? 'done' : 'todo';
        }
    @endphp

    {{-- Page head: title + status pill and Branding link. Publishing now lives
         in the checklist card on the right, next to the reasons it may be
         blocked. (Requirement 1.4) --}}
    <div class="page-head">
        <div>
            <h1>{{ $event->name }}</h1>
            <p class="muted" style="margin:0;">
                <span class="pill {{ $event->isPublished() ? 'pill--live' : 'pill--draft' }}">
                    {{ $event->isPublished() ? 'Published' : 'Draft' }}
                </span>
            </p>
        </div>
        <div class="page-head__actions">
            @can('settings')
                <a class="btn btn-outline btn-sm" href="{{ route('dashboard.branding.event.edit', $event) }}">Branding</a>
            @endcan
        </div>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    {{-- A failed publish flashes 'publish_errors'; the checklist card is the one
         on-screen source of truth for what's missing, so we just nudge toward it. --}}
    @if (session('publish_errors'))
        <p class="status" data-status="warning" role="status">
            This event can’t be published yet — see the setup checklist for what’s still needed.
        </p>
    @endif

    {{-- Hero banner (per-event poster, falling back to company poster) ---- --}}
    @include('dashboard.events._hero', ['event' => $event])

    {{-- Two-column layout: tabbed content on the left, the pinned publish
         checklist on the right. --}}
    <div class="event-layout">
        <div class="event-layout__main">
            @php
                $tabs = [
                    'overview' => 'Overview',
                    'ticket-types' => 'Ticket types',
                    'location' => 'Location',
                    'share' => 'Share & QR',
                    'report' => 'Report',
                    'orders' => 'Orders',
                ];
            @endphp
            @include('dashboard.events._tabs', ['tabs' => $tabs, 'tabStatus' => $tabStatus])

            {{-- Overview: stats + capacity + event-details edit form. The
                 readiness checklist now lives in the right-hand card, so it is
                 no longer duplicated here. (Requirement 1.4) --}}
            <section role="tabpanel" id="panel-overview" aria-labelledby="tab-overview" data-tabpanel="overview" tabindex="0">
                <h2 class="sr-only">Overview</h2>

                @include('dashboard.events._summary', ['event' => $event, 'report' => $report])
                @include('dashboard.events._capacity', ['capacity' => $capacity, 'event' => $event])

                <div class="panel form-panel">
                    <div class="panel__head">
                        <h2>Event details</h2>
                    </div>
                    <form id="event-details-form" method="POST"
                          action="{{ route('dashboard.events.update', $event) }}"
                          enctype="multipart/form-data" class="stack">
                        @csrf
                        @method('PUT')
                        @include('dashboard.events._form', ['event' => $event])
                        <div class="form-actions">
                            <button type="submit" class="btn">Save changes</button>
                        </div>
                    </form>
                </div>
            </section>

            {{-- Ticket types: inline ticket-type management + comp issuance. --}}
            <section role="tabpanel" id="panel-ticket-types" aria-labelledby="tab-ticket-types" data-tabpanel="ticket-types" tabindex="0">
                <h2 class="sr-only">Ticket types</h2>

                @include('dashboard.events._ticket_types', [
                    'event' => $event,
                    'ticketTypes' => $ticketTypes,
                    'eventRemaining' => $eventRemaining,
                ])
                @include('dashboard.events._comp', [
                    'event' => $event,
                    'ticketTypes' => $ticketTypes,
                    'eventRemaining' => $eventRemaining,
                ])
            </section>

            {{-- Location: its own event-update form (a single form cannot span
                 two tab panels). update()'s validation requires `name`, so we
                 carry it as a hidden input; other event fields are absent from
                 the request and therefore left unchanged. --}}
            <section role="tabpanel" id="panel-location" aria-labelledby="tab-location" data-tabpanel="location" tabindex="0">
                <h2 class="sr-only">Location</h2>

                <form method="POST" action="{{ route('dashboard.events.update', $event) }}"
                      enctype="multipart/form-data" class="stack">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="name" value="{{ old('name', $event->name) }}">
                    @include('dashboard.events._location', ['event' => $event])
                    <div class="form-actions">
                        <button type="submit" class="btn">Save location</button>
                    </div>
                </form>
            </section>

            {{-- Share & QR --}}
            <section role="tabpanel" id="panel-share" aria-labelledby="tab-share" data-tabpanel="share" tabindex="0">
                <h2 class="sr-only">Share & QR</h2>

                @include('dashboard.events._share', ['publicUrl' => $publicUrl, 'event' => $event])
            </section>

            {{-- Report: at-a-glance figures + link to the dedicated report page. --}}
            <section role="tabpanel" id="panel-report" aria-labelledby="tab-report" data-tabpanel="report" tabindex="0">
                <h2 class="sr-only">Report</h2>

                @include('dashboard.events._summary', ['event' => $event, 'report' => $report])
                <div class="form-actions">
                    <a class="btn btn-outline btn-sm" href="{{ route('dashboard.events.report', $event) }}">View full report</a>
                </div>
            </section>

            {{-- Orders: recent orders with cancel/refund --}}
            <section role="tabpanel" id="panel-orders" aria-labelledby="tab-orders" data-tabpanel="orders" tabindex="0">
                <h2 class="sr-only">Orders</h2>

                @include('dashboard.events._orders', ['recentOrders' => $recentOrders])
            </section>
        </div>

        {{-- Right column: the pinned publish checklist + publish control. --}}
        <aside class="event-layout__aside" aria-label="Publish checklist">
            @include('dashboard.events._publish_card', ['event' => $event, 'readiness' => $readiness])
        </aside>
    </div>
@endsection
