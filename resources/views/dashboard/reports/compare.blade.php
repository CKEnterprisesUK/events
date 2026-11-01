@extends('layouts.dashboard')

@section('title', 'Compare events')

@section('page_title', 'Compare events')

@section('content')
    @php
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $symbol = $symbols[$currency] ?? '';
        $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);

        // A small, fixed palette so each event gets a stable colour across both
        // charts and the totals strip.
        $palette = ['#674df3', '#0ea5e9', '#f59e0b'];
    @endphp

    <section>
        <div class="page-header">
            <div class="page-header__text">
                <h1>Compare events</h1>
                <p class="page-header__desc">
                    Put two or three events side by side: how sales built up in the run-up to each
                    event, and how sales spread across the hours of the day. Figures are gross sales
                    from paid and confirmed orders, in {{ $currency }} ({{ $symbol !== '' ? $symbol : $currency }}).
                </p>
            </div>
            <div class="page-header__actions">
                <a class="btn btn-outline" href="{{ route('dashboard.reports.index') }}">Back to reports</a>
            </div>
        </div>

        {{-- Event picker: choose up to three events to overlay. Submitting
             reloads the charts for the chosen set. --}}
        <form method="GET" action="{{ route('dashboard.reports.compare') }}" class="report-filter compare-picker">
            @for ($i = 0; $i < 3; $i++)
                <div class="field">
                    <label for="event-{{ $i }}">{{ $i === 0 ? 'Event A' : ($i === 1 ? 'Event B' : 'Event C (optional)') }}</label>
                    <select id="event-{{ $i }}" name="events[]">
                        <option value="">{{ $i < 2 ? 'Choose an event' : 'None' }}</option>
                        @foreach ($events as $id => $name)
                            <option value="{{ $id }}" @selected((string) ($selectedIds[$i] ?? '') === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @endfor
            <div class="report-filter__actions">
                <button type="submit" class="btn btn-sm">Compare</button>
            </div>
        </form>

        @if ($comparison === null)
            <div class="panel">
                <p class="table-empty-filter">
                    Pick at least two events above, then select Compare to see the charts.
                </p>
            </div>
        @else
            {{-- Headline totals per event, each in its series colour. --}}
            <div class="summary-row">
                @foreach ($comparison['totals'] as $i => $total)
                    <div class="metric-card">
                        <span class="metric-card__label" style="color: {{ $palette[$i] ?? 'var(--muted)' }};">
                            &#9632; {{ $total['event_name'] }}
                        </span>
                        <span class="metric-card__value">{{ $money($total['gross_minor']) }}</span>
                        <span class="metric-card__sub">
                            {{ number_format($total['orders']) }} {{ \Illuminate\Support\Str::plural('order', $total['orders']) }}
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- Chart 1: cumulative sales in the lead-up to each event, on a
                 shared "days before the event" axis so differently-dated events
                 line up. --}}
            @php
                $leadUpSeries = [];
                foreach ($comparison['lead_up'] as $i => $row) {
                    if (empty($row['points'])) { continue; }
                    $leadUpSeries[] = [
                        'name' => $row['event_name'],
                        'color' => $palette[$i] ?? 'var(--brand)',
                        'points' => array_map(fn ($p) => [
                            'label' => $p['label'],
                            'value' => $p['cumulative_minor'],
                        ], $row['points']),
                    ];
                }
            @endphp
            <div class="panel">
                <div class="panel__head">
                    <h2>Sales in the lead-up to the event</h2>
                </div>
                <div class="chart-body">
                    @if (empty($leadUpSeries))
                        <p class="chart-empty">
                            Neither event has a start date set, so there's no run-up to chart.
                        </p>
                    @else
                        <p class="muted" style="margin-bottom: 0.75rem;">
                            Cumulative gross sales across the {{ \App\Services\EventComparisonService::LEAD_UP_DAYS }} days
                            before each event's start, ending on event day.
                        </p>
                        @include('dashboard.partials.line-chart', [
                            'series' => $leadUpSeries,
                            'formatY' => $money,
                            'height' => 280,
                            'yLabel' => 'cumulative gross sales',
                        ])
                    @endif
                </div>
            </div>

            {{-- Chart 2: gross sales by hour of day, overlaid. --}}
            @php
                $hourSeries = [];
                foreach ($comparison['time_of_day'] as $i => $row) {
                    $hourSeries[] = [
                        'name' => $row['event_name'],
                        'color' => $palette[$i] ?? 'var(--brand)',
                        'points' => array_map(fn ($p) => [
                            'label' => $p['label'],
                            'value' => $p['total_minor'],
                        ], $row['points']),
                    ];
                }
            @endphp
            <div class="panel">
                <div class="panel__head">
                    <h2>Sales by time of day</h2>
                </div>
                <div class="chart-body">
                    <p class="muted" style="margin-bottom: 0.75rem;">
                        Gross sales grouped by the hour each order was placed.
                    </p>
                    @include('dashboard.partials.line-chart', [
                        'series' => $hourSeries,
                        'formatY' => $money,
                        'height' => 260,
                        'yLabel' => 'gross sales by hour',
                    ])
                </div>
            </div>
        @endif
    </section>
@endsection
