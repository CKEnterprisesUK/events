@extends('layouts.dashboard')

@section('title', 'Dashboard')

@section('content')
    @php
        $user = auth()->user();
        // $company is resolved in the controller (handles Super_Admin
        // impersonation) — do NOT re-derive it from $user here, or an
        // impersonating Super_Admin would see the wrong company.
        $firstName = explode(' ', trim($user->name))[0] ?? $user->name;
        $money = function (int $minor) use ($currency) {
            $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
            $symbol = $symbols[$currency] ?? '';
            return $symbol . number_format($minor / 100, 2);
        };
    @endphp

    <div class="dash-head">
        <div>
            <p class="dash-eyebrow">{{ now()->format('l, j F Y') }}</p>
            <h1>Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}, {{ $firstName }}</h1>
            @if ($company)
                <p class="muted">{{ $company->name }}</p>
            @endif
        </div>
        @can('view_events')
            <div class="dash-head__actions">
                <a class="btn" href="{{ route('dashboard.events.index') }}">Manage events</a>
            </div>
        @endcan
    </div>

    {{-- New-customer onboarding checklist. Shown only to the Owner and only
         until all four setup steps are complete, after which it disappears. --}}
    @if (!empty($onboarding))
        <div class="panel onboarding">
            <div class="panel__head">
                <div>
                    <h2>Get set up</h2>
                    <p class="muted">Finish these {{ $onboarding->totalCount() }} steps to start selling tickets.</p>
                </div>
                <span class="onboarding__count">{{ $onboarding->completedCount() }} of {{ $onboarding->totalCount() }} done</span>
            </div>

            <ol class="onboarding__list">
                @foreach ($onboarding->steps() as $step)
                    <li class="onboarding__step {{ $step->complete ? 'is-complete' : '' }}">
                        <span class="onboarding__check" aria-hidden="true">{{ $step->complete ? '✓' : '' }}</span>
                        <span class="onboarding__body">
                            <span class="onboarding__title">{{ $step->title }}</span>
                            <span class="onboarding__desc">{{ $step->description }}</span>
                        </span>
                        @if ($step->complete)
                            <span class="onboarding__status">Done</span>
                        @else
                            <a class="btn btn-sm" href="{{ route($step->routeName) }}">{{ $step->actionLabel }}</a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    @if (!$company && !$stats)
        <div class="panel">
            <div class="empty">
                <p>No company selected. Jump into a company from the admin area to view its dashboard.</p>
                <a class="btn btn-sm" href="{{ route('admin.home') }}">Go to admin</a>
            </div>
        </div>
    @endif

    @if ($stats)
        {{-- Headline metrics. Restrained bordered cards; money terms match the
             Reports page (Gross sales / Net to company). --}}
        <div class="metric-row">
            <div class="metric-card">
                <span class="metric-card__label">Events</span>
                <span class="metric-card__value">{{ number_format($stats['total_events']) }}</span>
                <span class="metric-card__sub">{{ number_format($stats['published_events']) }} published</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Tickets sold</span>
                <span class="metric-card__value">{{ number_format($stats['tickets_sold']) }}</span>
                <span class="metric-card__sub">Valid tickets on confirmed orders</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Confirmed orders</span>
                <span class="metric-card__value">{{ number_format($stats['confirmed_orders']) }}</span>
                <span class="metric-card__sub">Paid &amp; free-confirmed</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Gross sales</span>
                <span class="metric-card__value">{{ $money($stats['gross_sales_minor']) }}</span>
                <span class="metric-card__sub">Ticket subtotal</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Net payout</span>
                <span class="metric-card__value">{{ $money($stats['net_payout_minor']) }}</span>
                <span class="metric-card__sub">After platform &amp; Stripe fees</span>
            </div>
        </div>
    @endif

    {{-- Event-day operations: events starting within the next 24 hours or
         already under way get a compact action panel, check-in progress and a
         link to the full-screen live dashboard. --}}
    @can('view_events')
        @if ($operationalEvents->isNotEmpty())
            <div class="panel ops-panel">
                <div class="panel__head">
                    <div>
                        <h2>Happening soon</h2>
                        <p class="muted">Events starting within 24 hours, or under way now.</p>
                    </div>
                </div>
                @foreach ($operationalEvents as $event)
                    @php
                        $counts = $event->ticket_counts;
                        $checkInPct = $counts->sold > 0 ? min(100, round($counts->checkedIn / $counts->sold * 100, 1)) : 0;
                    @endphp
                    <div class="ops-event">
                        <div class="ops-event__info">
                            <a class="cell-strong row-link" href="{{ route('dashboard.events.show', $event) }}">{{ $event->name }}</a>
                            <span class="cell-dim">
                                {{ $event->starts_at->format('D j M, H:i') }}
                                &middot; {{ $event->doors_open ? 'Doors open' : $event->starts_at->diffForHumans() }}
                            </span>
                        </div>
                        <div class="ops-event__progress">
                            <div class="ops-event__figures">
                                <strong>{{ number_format($counts->checkedIn) }}</strong>
                                <span class="muted">of {{ number_format($counts->sold) }} checked in</span>
                                <span class="ops-event__pct">{{ number_format($checkInPct, 1) }}%</span>
                            </div>
                            <span class="sell-through__bar ops-event__bar" role="img"
                                  aria-label="{{ number_format($checkInPct, 1) }} percent checked in ({{ number_format($counts->checkedIn) }} of {{ number_format($counts->sold) }})">
                                <span class="sell-through__fill" style="width: {{ $checkInPct }}%"></span>
                            </span>
                            <span class="cell-dim">{{ number_format($counts->awaitingCheckIn()) }} still to arrive</span>
                        </div>
                        <div class="ops-event__actions">
                            <a class="btn btn-sm" href="{{ route('dashboard.events.orders', $event) }}">View orders</a>
                            @can('check_in')
                                <a class="btn btn-sm" href="{{ route('dashboard.scan.index') }}">Scan tickets</a>
                            @endcan
                            @can('view_reports')
                                <a class="btn btn-sm" href="{{ route('dashboard.events.attendance', $event) }}">View attendees</a>
                            @endcan
                            <a class="btn btn-sm" href="{{ route('dashboard.events.show', $event) }}">Manage event</a>
                            @can('view_reports')
                                <a class="btn btn-sm btn--live" href="{{ route('dashboard.events.live', $event) }}">Live dashboard</a>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endcan

    {{-- Upcoming events: soonest-first, the operationally urgent view. --}}
    @can('view_events')
        @if ($upcomingEvents->isEmpty())
            <div class="panel">
                <div class="panel__head">
                    <h2>Upcoming events</h2>
                    <a class="panel__link" href="{{ route('dashboard.events.index') }}">View all</a>
                </div>
                <div class="empty">
                    <p>{{ $stats && $stats['total_events'] > 0 ? 'You have no upcoming events.' : "You haven't created any events yet." }}</p>
                    <a class="btn btn-sm" href="{{ route('dashboard.events.index') }}">{{ $stats && $stats['total_events'] > 0 ? 'Manage events' : 'Create your first event' }}</a>
                </div>
            </div>
        @else
            <div class="panel">
                <div class="panel__head">
                    <h2>Upcoming events</h2>
                    <a class="panel__link" href="{{ route('dashboard.events.index') }}">View all</a>
                </div>
                <table class="data-table only-desktop">
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">When</th>
                            <th scope="col">Status</th>
                            <th scope="col">Sold</th>
                            <th scope="col" class="num">Orders</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($upcomingEvents as $event)
                            <tr class="row-nav">
                                <td>
                                    <a class="cell-strong row-link" href="{{ route('dashboard.events.show', $event) }}">{{ $event->name }}</a>
                                    @if ($event->venue)
                                        <span class="cell-dim">{{ $event->venue }}</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $event->starts_at->format('j M Y, H:i') }}
                                    <span class="cell-dim">{{ $event->starts_at->diffForHumans() }}</span>
                                </td>
                                <td>
                                    <span class="pill {{ $event->isPublished() ? 'pill--live' : 'pill--draft' }}">
                                        {{ $event->isPublished() ? 'Published' : 'Draft' }}
                                    </span>
                                </td>
                                <td>@include('dashboard.partials.sell-through', ['sellThrough' => $event->sell_through])</td>
                                <td class="num">{{ number_format($event->confirmed_orders_count) }}</td>
                                <td class="num">
                                    <a class="row-go row-action" href="{{ route('dashboard.events.show', $event) }}" aria-label="Open {{ $event->name }}">
                                        <x-icon name="chevron" />
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="only-mobile record-list" style="padding: 1rem 1.25rem;">
                    @foreach ($upcomingEvents as $event)
                        @include('dashboard.partials.event-card', ['event' => $event])
                    @endforeach
                </div>
            </div>
        @endif
    @endcan

    {{-- Sales trend: selectable window and metric, with a same-length previous
         period overlaid for comparison and hover tooltips with exact figures. --}}
    @if (!empty($salesChart))
        @can('view_events')
            @php
                $chart = $salesChart;
                $delta = $chart['delta_pct'];
                $isTickets = $chart['metric'] === 'tickets';
                $chartTotal = $isTickets ? number_format($chart['current_total']) . ' tickets sold' : $money($chart['current_total']) . ' in gross sales';
                $chartPrevious = $isTickets ? number_format($chart['previous_total']) . ' tickets' : $money($chart['previous_total']);
                $hasChartData = $chart['max_value'] > 0;
            @endphp
            <div class="panel">
                <div class="panel__head panel__head--wrap">
                    <div>
                        <h2>{{ $isTickets ? 'Tickets sold' : 'Sales' }} over the last {{ $chart['range_label'] }}</h2>
                        <p class="muted">{{ $chartTotal }}</p>
                    </div>
                    @if ($delta !== null)
                        @php $up = $delta >= 0; @endphp
                        <span class="trend {{ $up ? 'trend--up' : 'trend--down' }}"
                              title="Compared with the previous {{ $chart['range_label'] }} ({{ $chartPrevious }}). The current {{ $chart['unit'] === 'month' ? 'month' : 'day' }} is still in progress.">
                            {{ $up ? '▲' : '▼' }} {{ number_format(abs($delta), 1) }}%
                            <span class="trend__note">vs previous {{ $chart['range_label'] }}</span>
                        </span>
                    @else
                        <span class="trend trend--flat" title="Nothing recorded in the previous {{ $chart['range_label'] }} to compare against">
                            New activity
                        </span>
                    @endif
                </div>

                <div class="chart-controls">
                    <div class="seg" role="group" aria-label="Time range">
                        @foreach (\App\Http\Controllers\DashboardController::CHART_RANGES as $key => $def)
                            <a class="seg__item {{ $chartRange === $key ? 'is-active' : '' }}"
                               href="{{ route('dashboard.home', ['range' => $key, 'metric' => $chartMetric]) }}"
                               @if ($chartRange === $key) aria-current="true" @endif>{{ $key === '12m' ? '12 months' : $def[0] . ' days' }}</a>
                        @endforeach
                    </div>
                    <div class="seg" role="group" aria-label="Metric">
                        <a class="seg__item {{ $chartMetric === 'revenue' ? 'is-active' : '' }}"
                           href="{{ route('dashboard.home', ['range' => $chartRange, 'metric' => 'revenue']) }}"
                           @if ($chartMetric === 'revenue') aria-current="true" @endif>Revenue</a>
                        <a class="seg__item {{ $chartMetric === 'tickets' ? 'is-active' : '' }}"
                           href="{{ route('dashboard.home', ['range' => $chartRange, 'metric' => 'tickets']) }}"
                           @if ($chartMetric === 'tickets') aria-current="true" @endif>Tickets sold</a>
                    </div>
                </div>

                <div class="chart-body">
                    @if ($hasChartData)
                        @include('dashboard.partials.sales-chart', ['chart' => $chart, 'money' => $money])
                    @else
                        <p class="chart-empty">No {{ $isTickets ? 'ticket sales' : 'sales' }} in this period or the one before it.</p>
                    @endif
                </div>
            </div>
        @endcan
    @endif

    {{-- Quick tool entry points, shown to anyone holding the relevant
         permission — Owners and Admins see everything they're allowed, not
         just users who can't manage events. Each row is individually gated. --}}
    @if (auth()->user()->can('view_reports') || auth()->user()->can('check_in'))
        <div class="panel">
            <div class="panel__head"><h2>Your tools</h2></div>
            <table class="data-table">
                <tbody>
                    @can('view_reports')
                        <tr>
                            <td><span class="cell-strong">Reports &amp; payouts</span><span class="cell-dim">Realised sales, fees and net payouts</span></td>
                            <td class="num"><a class="panel__link" href="{{ route('dashboard.reports.index') }}">Open</a></td>
                        </tr>
                    @endcan
                    @can('check_in')
                        <tr>
                            <td><span class="cell-strong">Scan tickets</span><span class="cell-dim">Check attendees in at the door</span></td>
                            <td class="num"><a class="panel__link" href="{{ route('dashboard.scan.index') }}">Open</a></td>
                        </tr>
                    @endcan
                </tbody>
            </table>
        </div>
    @endif

    @if ($company)
        <div class="panel panel--storefront">
            <div>
                <h2>Your storefront</h2>
                <p class="muted">Customers buy tickets from your public page.</p>
            </div>
            <a class="storefront-url" href="{{ url('/' . $company->slug) }}" target="_blank" rel="noopener">
                {{ rtrim(preg_replace('#^https?://#', '', url('/')), '/') }}/{{ $company->slug }}
            </a>
        </div>
    @endif
@endsection

@push('head')
<style>
    .dash-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .dash-head h1 { margin: 0.15rem 0 0.25rem; }
    .dash-head .muted { margin: 0; }
    .dash-eyebrow { text-transform: uppercase; letter-spacing: 0.06em; font-size: 0.72rem; color: var(--muted); margin: 0; }

    .panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-card); overflow: hidden; margin-bottom: 1.5rem; }
    .panel__head { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }
    .panel__head h2 { margin: 0; font-size: 1.05rem; }
    .panel__link { font-size: 0.9rem; font-weight: 600; text-decoration: none; }

    .data-table { width: 100%; border-collapse: collapse; background: transparent; border-radius: 0; }
    .data-table th, .data-table td { padding: 0.8rem 1.25rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .data-table thead th { background: transparent; }
    .data-table tbody tr:last-child td { border-bottom: none; }
    .data-table .num { text-align: right; }
    .cell-strong { display: block; font-weight: 600; color: var(--ink); text-decoration: none; }
    .cell-strong:hover { color: var(--brand); }
    .cell-dim { display: block; font-size: 0.82rem; color: var(--muted); }

    .pill { display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
    .pill--live { background: #ecfdf5; color: #047857; }
    .pill--draft { background: #f3f4f6; color: #6b7280; }
    .pill--cancelled { background: #fef2f2; color: #b91c1c; }

    .empty { padding: 2rem 1.25rem; text-align: center; color: var(--muted); }
    .empty p { margin: 0 0 0.75rem; }

    .panel--storefront { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: 1.25rem; }
    .panel--storefront h2 { margin: 0 0 0.15rem; font-size: 1.05rem; }
    .panel--storefront .muted { margin: 0; }
    .storefront-url {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9rem;
        background: #f3f4f6; border: 1px solid var(--border); border-radius: 0.5rem;
        padding: 0.5rem 0.85rem; text-decoration: none; color: var(--ink);
    }
    .storefront-url:hover { border-color: var(--brand); color: var(--brand); }

    .onboarding { border-top: 3px solid var(--brand); }
    .onboarding .panel__head { align-items: flex-start; }
    .onboarding .panel__head .muted { margin: 0.15rem 0 0; font-size: 0.85rem; }
    .onboarding__count { font-size: 0.8rem; font-weight: 600; color: var(--muted); white-space: nowrap; }
    .onboarding__list { list-style: none; margin: 0; padding: 0; }
    .onboarding__step {
        display: flex; align-items: center; gap: 0.9rem;
        padding: 0.9rem 1.25rem; border-bottom: 1px solid var(--border);
    }
    .onboarding__step:last-child { border-bottom: none; }
    .onboarding__check {
        flex: none; width: 1.5rem; height: 1.5rem; border-radius: 999px;
        border: 2px solid var(--border); display: inline-flex; align-items: center;
        justify-content: center; font-size: 0.8rem; font-weight: 700; color: #fff;
    }
    .onboarding__step.is-complete .onboarding__check { background: #047857; border-color: #047857; }
    .onboarding__body { flex: 1 1 auto; display: flex; flex-direction: column; gap: 0.1rem; }
    .onboarding__title { font-weight: 600; color: var(--ink); }
    .onboarding__step.is-complete .onboarding__title { color: var(--muted); text-decoration: line-through; }
    .onboarding__desc { font-size: 0.82rem; color: var(--muted); }
    .onboarding__status { font-size: 0.8rem; font-weight: 600; color: #047857; white-space: nowrap; }
    .field-hint { display: block; font-size: 0.8rem; color: var(--muted); margin-top: 0.2rem; }


    .panel__head--wrap { flex-wrap: wrap; gap: .5rem 1rem; }
    .panel__head .muted { margin: .15rem 0 0; font-size: .85rem; }
    .chart-controls { display: flex; flex-wrap: wrap; gap: .5rem 1rem; padding: .5rem 1.25rem 0; }
    .seg { display: inline-flex; border: 1px solid var(--border); border-radius: .5rem; overflow: hidden; background: var(--surface); }
    .seg__item { padding: .25rem .7rem; font-size: .8rem; font-weight: 600; color: var(--muted); text-decoration: none; border-right: 1px solid var(--border); }
    .seg__item:last-child { border-right: none; }
    .seg__item:hover { color: var(--ink); background: #f9fafb; }
    .seg__item.is-active { background: var(--brand); color: #fff; }
    .chart-body { padding: .75rem 1.25rem 1rem; }
    .chart-empty { margin: 0; padding: 1.5rem 0; text-align: center; color: var(--muted); }

    .ops-panel { border-top: 3px solid #d97706; }
    .ops-panel .panel__head .muted { margin: .15rem 0 0; font-size: .85rem; }
    .ops-event { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) auto; gap: 1rem 1.5rem; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }
    .ops-event:last-child { border-bottom: none; }
    .ops-event__figures { display: flex; align-items: baseline; gap: .4rem; font-variant-numeric: tabular-nums; }
    .ops-event__figures strong { font-size: 1.25rem; }
    .ops-event__pct { margin-left: auto; font-weight: 700; color: var(--ink); }
    .ops-event__bar { display: block; width: 100%; height: 8px; margin: .35rem 0 .2rem; }
    .ops-event__actions { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: flex-end; }
    .btn--live { background: #111827; color: #fff; }
    @media (max-width: 900px) {
        .ops-event { grid-template-columns: 1fr; }
        .ops-event__actions { justify-content: flex-start; }
    }

    .sell-through__meta { font-size: .72rem; color: var(--muted); }

    .trend {
        display: inline-flex; align-items: baseline; gap: 0.35rem;
        font-size: 0.9rem; font-weight: 700; white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }
    .trend--up { color: #047857; }
    .trend--down { color: #b91c1c; }
    .trend--flat { color: var(--muted); font-weight: 600; }
    .trend__note { font-size: 0.72rem; font-weight: 500; color: var(--muted); }

    .sell-through { display: inline-flex; flex-direction: column; gap: 0.2rem; min-width: 84px; font-variant-numeric: tabular-nums; }
    .sell-through__count { font-size: 0.85rem; font-weight: 600; color: var(--ink); }
    .sell-through__cap { color: var(--muted); font-weight: 400; }
    .sell-through__bar { display: block; height: 5px; width: 84px; border-radius: 999px; background: var(--border); overflow: hidden; }
    .sell-through__fill { display: block; height: 100%; background: var(--brand); border-radius: 999px; }
    .sell-through.is-almost-full .sell-through__fill { background: #d97706; }
    .sell-through.is-sold-out .sell-through__fill { background: #047857; }
    .sell-through__flag { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.02em; }
    .sell-through.is-almost-full .sell-through__flag { color: #b45309; }
    .sell-through.is-sold-out .sell-through__flag { color: #047857; }
    .sell-through--unlimited .sell-through__count { color: var(--ink); }
</style>
@endpush
