@extends('layouts.dashboard')

@section('title', $event->name)

@section('content')
    {{-- Page head: title + status pill, publish/unpublish controls, branding
         link. The ticket-types quick link is intentionally removed — it now
         lives in the "Ticket types" tab. (Requirement 1.4) --}}
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
            @unless ($event->isPublished())
                @php $publishBlockers = $event->publishBlockers(); @endphp
                <div class="publish-control">
                    <form method="POST" action="{{ route('dashboard.events.publish', $event) }}" class="inline-form">
                        @csrf
                        <button type="submit" class="btn btn-sm" @disabled(! empty($publishBlockers))>Publish</button>
                    </form>
                    @if (! empty($publishBlockers))
                        <p class="hint muted" style="margin:.35rem 0 0;">
                            Before publishing: {{ implode(' ', $publishBlockers) }}
                        </p>
                    @endif
                </div>
            @else
                <form method="POST" action="{{ route('dashboard.events.unpublish', $event) }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn btn-outline btn-sm">Unpublish</button>
                </form>
            @endunless
        </div>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    @if (session('publish_errors'))
        <div class="panel">
            <div class="panel__head"><h2>Can’t publish yet</h2></div>
            <ul class="error-list">
                @foreach ((array) session('publish_errors') as $publishError)
                    <li class="error">{{ $publishError }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Hero banner (per-event poster, falling back to company poster) ---- --}}
    @include('dashboard.events._hero', ['event' => $event])

    {{-- Tab strip. Panels below are rendered visible server-side; the tabs JS
         hides inactive ones on init and honours ?tab= / #tab- deep-links.
         (Requirements 1.1, 1.2, 1.3, 1.8) --}}
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
    @include('dashboard.events._tabs', ['tabs' => $tabs])

    {{-- Overview: stats + readiness checklist + capacity + event-details edit
         form. (Requirement 1.4) --}}
    <section role="tabpanel" id="panel-overview" aria-labelledby="tab-overview" data-tabpanel="overview" tabindex="0">
        <h2 class="sr-only">Overview</h2>

        @include('dashboard.events._summary', ['event' => $event, 'report' => $report])
        @include('dashboard.events._readiness', ['readiness' => $readiness, 'event' => $event])
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

    {{-- Ticket types: inline ticket-type management + comp issuance.
         (Requirement 1.4, 6.1, 6.2) --}}
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

    {{-- Location: its own event-update form so it can be saved independently of
         the Overview details form (a single form cannot span two tab panels).
         update()'s validation requires `name`, so we carry it as a hidden input;
         all other event fields are absent from the request and therefore left
         unchanged by update(). location_mode/address/latitude/longitude come
         from the _location partial. (Requirement 1.4, 4.1, 4.3) --}}
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

    {{-- Share & QR (Requirement 1.4) --}}
    <section role="tabpanel" id="panel-share" aria-labelledby="tab-share" data-tabpanel="share" tabindex="0">
        <h2 class="sr-only">Share & QR</h2>

        @include('dashboard.events._share', ['publicUrl' => $publicUrl, 'event' => $event])
    </section>

    {{-- Report: at-a-glance figures + link to the dedicated report page.
         (Requirement 1.4) --}}
    <section role="tabpanel" id="panel-report" aria-labelledby="tab-report" data-tabpanel="report" tabindex="0">
        <h2 class="sr-only">Report</h2>

        @include('dashboard.events._summary', ['event' => $event, 'report' => $report])
        <div class="form-actions">
            <a class="btn btn-outline btn-sm" href="{{ route('dashboard.events.report', $event) }}">View full report</a>
        </div>
    </section>

    {{-- Orders: recent orders with cancel/refund (Requirement 1.4) --}}
    <section role="tabpanel" id="panel-orders" aria-labelledby="tab-orders" data-tabpanel="orders" tabindex="0">
        <h2 class="sr-only">Orders</h2>

        @include('dashboard.events._orders', ['recentOrders' => $recentOrders])
    </section>
@endsection
