{{--
    Overview screen — a genuine at-a-glance summary of the event, NOT the main
    edit surface. Shows status/date/location, key metrics, the setup checklist
    and recent orders. Basic event details editing lives lower on the page in a
    dedicated "Details" section (anchored #event-details, reached from the
    sidebar "Details" link), still POSTing to dashboard.events.update.
    (Requirements: event overview / details separation)
--}}
@extends('layouts.event')

@section('active_section', 'overview')

@section('section')
    @php
        $venue = $event->venue;
        $startsAt = $event->starts_at;
        $currency = $event->company->currency ?? 'GBP';
        $sym = $currency === 'GBP' ? '£' : '';
    @endphp

    {{-- Date / location summary line. --}}
    <div class="overview-summary">
        <div class="overview-summary__item">
            <span class="overview-summary__label">When</span>
            <span class="overview-summary__value">
                {{ $startsAt ? $startsAt->format('j M Y · H:i') : 'Date not set' }}
            </span>
        </div>
        <div class="overview-summary__item">
            <span class="overview-summary__label">Where</span>
            <span class="overview-summary__value">
                @if ($event->isOnline())
                    Online
                @elseif ($venue)
                    {{ $venue }}
                @else
                    <a href="{{ route('dashboard.events.location', $event) }}">Add venue</a>
                @endif
            </span>
        </div>
    </div>

    {{-- Key metrics. Restrained bordered cards, consistent money terms with the
         Reports page (Gross sales / Net revenue). --}}
    <div class="metric-row" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
        <div class="metric-card">
            <span class="metric-card__label">Tickets sold</span>
            <span class="metric-card__value">{{ number_format($report->ticketsSold) }}</span>
        </div>
        <div class="metric-card">
            <span class="metric-card__label">Confirmed orders</span>
            <span class="metric-card__value">{{ number_format($report->confirmedOrders) }}</span>
        </div>
        <div class="metric-card">
            <span class="metric-card__label">Gross sales</span>
            <span class="metric-card__value">{{ $sym }}{{ number_format($report->grossRevenueMinor / 100, 2) }}</span>
        </div>
        <div class="metric-card">
            <span class="metric-card__label">Net revenue</span>
            <span class="metric-card__value">{{ $sym }}{{ number_format($report->netToCompanyMinor / 100, 2) }}</span>
        </div>
        <div class="metric-card">
            <span class="metric-card__label">Capacity used</span>
            <span class="metric-card__value">
                @if ($report->utilisation() === 'unlimited' || $report->capacity === null)
                    &infin;
                @else
                    {{ $report->utilisation() }}%
                @endif
            </span>
        </div>
    </div>

    @can('view_reports')
        <p class="overview-report-link">
            <a class="btn btn-outline btn-sm" href="{{ route('dashboard.events.report', $event) }}">View full report</a>
        </p>
    @endcan

    {{-- Setup checklist (inline, collapsible). --}}
    @include('dashboard.events._setup_checklist', ['event' => $event, 'readiness' => $readiness])

    {{-- Details editing — a clearly separated section, not the top of the page. --}}
    <div class="panel form-panel" id="event-details">
        <div class="panel__head">
            <h2>Details</h2>
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
@endsection
