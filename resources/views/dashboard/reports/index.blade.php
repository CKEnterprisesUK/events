@extends('layouts.dashboard')

@section('title', 'Reports & Payouts')

@section('mobile_context', 'Reports & Payouts')

@section('content')
    @php
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $symbol = $symbols[$currency] ?? '';
        $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);
    @endphp

    <section>
        @php
            // Every filter the page offers, carried through tabs, export links
            // and the compare view so what is exported matches what is on screen.
            $filterQuery = array_filter(['from' => $from ?? null, 'to' => $to ?? null, 'event' => $eventId ?? null], fn ($v) => $v !== null && $v !== '');
            $exportQuery = $filterQuery + ['tab' => $tab];
            $hasFilters = ! empty($from) || ! empty($to) || ! empty($eventId);
            $percent = fn (?float $change) => $change === null
                ? null
                : ($change > 0 ? '+' : '').rtrim(rtrim(number_format($change, 1), '0'), '.').'%';
        @endphp
        <div class="page-header">
            <div class="page-header__text">
                <h1>Reports &amp; Analytics</h1>
                <p class="page-header__desc">
                    Understand ticket performance, revenue and payouts from paid and confirmed orders,
                    shown in {{ $currency }} ({{ $symbol !== '' ? $symbol : $currency }}).
                </p>
            </div>
            <div class="page-header__actions">
                <a class="btn btn-outline" href="{{ route('dashboard.reports.compare') }}">Compare events</a>
                {{-- One export menu. Every link carries the current filters. --}}
                <details class="export-menu">
                    <summary class="btn btn-outline">Export report</summary>
                    <div class="export-menu__list">
                        <a href="{{ route('dashboard.reports.export', $exportQuery) }}" download>CSV</a>
                        <a href="{{ route('dashboard.reports.export-excel', $exportQuery) }}" download>Excel</a>
                        <a href="{{ route('dashboard.reports.export-pdf', $exportQuery) }}">PDF statement</a>
                        <button type="button" onclick="window.print()">Print this page</button>
                    </div>
                </details>
            </div>
        </div>

        {{-- Event and date-range filters stay visible across every tab. Both
             dates are optional and inclusive. --}}
        <form method="GET" action="{{ route('dashboard.reports.index') }}" class="report-filter">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="field">
                <label for="event">Event</label>
                <select id="event" name="event">
                    <option value="">All events</option>
                    @foreach ($events as $id => $name)
                        <option value="{{ $id }}" @selected((string) ($eventId ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="{{ $from ?? '' }}">
            </div>
            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="{{ $to ?? '' }}">
            </div>
            <div class="report-filter__actions">
                <button type="submit" class="btn btn-sm">Apply</button>
                @if ($hasFilters)
                    <a class="btn btn-sm btn-outline" href="{{ route('dashboard.reports.index', ['tab' => $tab]) }}">Clear</a>
                @endif
            </div>
        </form>

        @if ($hasFilters)
            <p class="panel__note" data-report-period>
                Showing
                @if (! empty($eventId))
                    <strong>{{ $events[$eventId] ?? 'selected event' }}</strong>@if (! empty($from) || ! empty($to)),@endif
                @endif
                @if (! empty($from) && ! empty($to))
                    {{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}
                @elseif (! empty($from))
                    from {{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }}
                @elseif (! empty($to))
                    up to {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}
                @endif
            </p>
        @endif

        <nav class="tab-list" aria-label="Report sections">
            @foreach ($tabs as $key => $label)
                <a class="tab-btn" href="{{ route('dashboard.reports.index', $filterQuery + ['tab' => $key]) }}"
                   @if ($key === $tab) aria-selected="true" aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        {{-- Headline figures for the current filters (sales-side tabs only). --}}
        @if ($tab !== 'attendance')
        <div class="summary-row">
            <div class="metric-card">
                <span class="metric-card__label">Confirmed orders</span>
                <span class="metric-card__value">{{ number_format($totals['orders']) }}</span>
                @if (($comparison['deltas']['orders']['change'] ?? null) !== null)
                    <span class="metric-card__sub">{{ $percent($comparison['deltas']['orders']['change']) }} vs previous period</span>
                @endif
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Tickets sold</span>
                <span class="metric-card__value">{{ number_format($totals['tickets_sold']) }}</span>
                @if (($comparison['deltas']['tickets_sold']['change'] ?? null) !== null)
                    <span class="metric-card__sub">{{ $percent($comparison['deltas']['tickets_sold']['change']) }} vs previous period</span>
                @endif
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Gross sales</span>
                <span class="metric-card__value">{{ $money($totals['gross_sales_minor']) }}</span>
                @if (($comparison['deltas']['gross_sales_minor']['change'] ?? null) !== null)
                    <span class="metric-card__sub">{{ $percent($comparison['deltas']['gross_sales_minor']['change']) }} vs previous period</span>
                @endif
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Revenue per order</span>
                <span class="metric-card__value">{{ $money($averageOrderMinor) }}</span>
                <span class="metric-card__sub">Gross sales ÷ orders</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Net payout</span>
                <span class="metric-card__value">{{ $money($totals['net_payout_minor']) }}</span>
                @if (($comparison['deltas']['net_payout_minor']['change'] ?? null) !== null)
                    <span class="metric-card__sub">{{ $percent($comparison['deltas']['net_payout_minor']['change']) }} vs previous period</span>
                @endif
            </div>
        </div>
        @endif

        @if ($tab === 'sales')
            @if ($comparison)
                <div class="panel">
                    <div class="panel__head"><h2>Gross sales vs previous period</h2></div>
                    <div class="chart-body">
                        @include('dashboard.partials.line-chart', [
                            'series' => [
                                ['name' => 'This period', 'color' => 'var(--brand)', 'points' => $comparison['series']],
                                ['name' => 'Previous period ('.$comparison['label'].')', 'color' => '#9ca3af', 'points' => $comparison['previous_series']],
                            ],
                            'formatY' => $money,
                            'height' => 240,
                            'yLabel' => 'gross sales',
                        ])
                    </div>
                    <p class="panel__note">
                        The previous period is the same number of days immediately before the selected range,
                        compared day for day.
                    </p>
                </div>
            @elseif (! empty($salesTimeline))
                <div class="panel">
                    <div class="panel__head"><h2>Gross sales over the period</h2></div>
                    <div class="chart-body">
                        @include('dashboard.partials.line-chart', [
                            'series' => [[
                                'name' => 'Gross sales',
                                'color' => 'var(--brand)',
                                'points' => $salesTimeline,
                            ]],
                            'formatY' => $money,
                            'height' => 240,
                            'yLabel' => 'gross sales',
                        ])
                    </div>
                    <p class="panel__note">Choose both a From and To date to compare against the previous period.</p>
                </div>
            @else
                <div class="panel"><p class="table-empty-filter">No confirmed sales for these filters.</p></div>
            @endif
        @endif

        @if ($tab === 'tickets')
            <div class="panel">
                <div class="panel__head"><h2>Sales by ticket type</h2></div>
                @if (empty($ticketTypes))
                    <p class="table-empty-filter">No ticket sales for these filters.</p>
                @else
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th scope="col">Ticket type</th>
                                    <th scope="col">Event</th>
                                    <th scope="col" class="num">Price</th>
                                    <th scope="col" class="num">Sold</th>
                                    <th scope="col" class="num">Revenue</th>
                                    <th scope="col" class="num">Share</th>
                                    <th scope="col" class="num">Remaining</th>
                                    <th scope="col">Sell-through</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ticketTypes as $row)
                                    <tr data-ticket-type-id="{{ $row['type_id'] }}">
                                        <td class="cell-strong">{{ $row['type_name'] }}</td>
                                        <td>{{ $row['event_name'] }}</td>
                                        <td class="num">{{ $row['price_minor'] === 0 ? 'Free' : $money($row['price_minor']) }}</td>
                                        <td class="num" data-metric="sold">{{ number_format($row['sold']) }}</td>
                                        <td class="num" data-metric="revenue_minor">{{ $money($row['revenue_minor']) }}</td>
                                        <td class="num">{{ rtrim(rtrim(number_format($row['share_percent'], 1), '0'), '.') }}%</td>
                                        <td class="num">{{ $row['remaining'] === null ? '—' : number_format($row['remaining']) }}</td>
                                        <td>
                                            @if ($row['sell_through_percent'] === null)
                                                <span class="cell-dim">No fixed allocation</span>
                                            @else
                                                <span class="sell-through {{ $row['sell_through_percent'] >= 100 ? 'is-sold-out' : ($row['sell_through_percent'] >= 90 ? 'is-almost-full' : '') }}">
                                                    <span class="sell-through__bar" role="img"
                                                          aria-label="{{ $row['sell_through_percent'] }} percent of {{ number_format($row['capacity']) }} allocated">
                                                        <span class="sell-through__fill" style="width: {{ min(100, $row['sell_through_percent']) }}%"></span>
                                                    </span>
                                                    <span class="sell-through__meta">{{ rtrim(rtrim(number_format($row['sell_through_percent'], 1), '0'), '.') }}% of {{ number_format($row['capacity']) }}</span>
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <p class="panel__note">
                    Sold and revenue follow the selected filters. Remaining and sell-through describe each
                    ticket type's whole allocation (sold plus held in checkout), so they are not date-filtered.
                    Revenue is quantity sold × current ticket price.
                </p>
            </div>
        @endif

        @if ($tab === 'finance')
        {{-- Full financial reconciliation — the detail matters, so it stays a
             table (with the fee/collected breakdown), not a wall of cards. --}}
        <div class="panel">
            <div class="panel__head"><h2>Company reconciliation</h2></div>
            <table class="data-table">
                <tbody>
                    <tr>
                        <th scope="row">Gross sales</th>
                        <td class="num" data-metric="gross_sales_minor">{{ $money($totals['gross_sales_minor']) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Booking fees</th>
                        <td class="num" data-metric="booking_fees_minor">{{ $money($totals['booking_fees_minor']) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Platform fees</th>
                        <td class="num" data-metric="application_fees_minor">{{ $money($totals['application_fees_minor']) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Total collected</th>
                        <td class="num" data-metric="order_total_minor">{{ $money($totals['order_total_minor']) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Net after platform fee</th>
                        <td class="num" data-metric="net_to_company_minor">{{ $money($totals['net_to_company_minor']) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Stripe processing fees</th>
                        <td class="num" data-metric="stripe_fees_minor">{{ $money($totals['stripe_fees_minor']) }}</td>
                    </tr>
                    <tr class="data-table__total">
                        <th scope="row">Net payout to bank</th>
                        <td class="num" data-metric="net_payout_minor">{{ $money($totals['net_payout_minor']) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="panel__note">
                Funds settle directly to your connected Stripe account. The platform fee is deducted at
                the point of sale; Stripe's own card-processing fee is taken inside your Stripe account.
                The net payout subtracts both, so it reflects what actually reaches your bank. Stripe fees
                are read from each settled charge, so a very recent sale may show a fee of zero until Stripe
                finalises it (usually within minutes).
            </p>
        </div>

            @if ($refunds)
                <div class="panel">
                    <div class="panel__head"><h2>Refunds</h2></div>
                    <table class="data-table">
                        <tbody>
                            <tr>
                                <th scope="row">Orders with a refund</th>
                                <td class="num" data-metric="refund_orders">{{ number_format($refunds['orders']) }}</td>
                            </tr>
                            <tr>
                                <th scope="row">Amount refunded</th>
                                <td class="num" data-metric="refunded_minor">{{ $money($refunds['amount_minor']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="panel__note">
                        Shown for information. Fully refunded orders are already excluded from the figures above;
                        partial refunds are not deducted from them.
                    </p>
                </div>
            @endif

        <div class="panel">
            <div class="panel__head"><h2>Per-event breakdown</h2></div>

            @if (empty($perEvent))
                <p class="table-empty-filter">No confirmed sales yet.</p>
            @else
                {{-- Desktop: the full financial table, horizontally scrollable
                     within its panel if the viewport is narrow, with the event
                     name column readable. --}}
                <div class="table-scroll only-desktop">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Event</th>
                                <th scope="col" class="num">Orders</th>
                                <th scope="col" class="num">Tickets sold</th>
                                <th scope="col" class="num">Gross sales</th>
                                <th scope="col" class="num">Booking fees</th>
                                <th scope="col" class="num">Platform fees</th>
                                <th scope="col" class="num">Total collected</th>
                                <th scope="col" class="num">Stripe fees</th>
                                <th scope="col" class="num">Net payout</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($perEvent as $row)
                                <tr data-event-id="{{ $row['event_id'] }}">
                                    <td class="cell-strong">{{ $row['event_name'] }}</td>
                                    <td class="num" data-metric="orders">{{ number_format($row['orders']) }}</td>
                                    <td class="num" data-metric="tickets_sold">{{ number_format($row['tickets_sold']) }}</td>
                                    <td class="num" data-metric="gross_sales_minor">{{ $money($row['gross_sales_minor']) }}</td>
                                    <td class="num" data-metric="booking_fees_minor">{{ $money($row['booking_fees_minor']) }}</td>
                                    <td class="num" data-metric="application_fees_minor">{{ $money($row['application_fees_minor']) }}</td>
                                    <td class="num" data-metric="order_total_minor">{{ $money($row['order_total_minor']) }}</td>
                                    <td class="num" data-metric="stripe_fees_minor">{{ $money($row['stripe_fees_minor']) }}</td>
                                    <td class="num" data-metric="net_payout_minor">{{ $money($row['net_payout_minor']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile: each event as a structured record instead of a
                     horizontally-scrolling financial table. --}}
                <div class="only-mobile record-list" style="padding: 1rem 1.25rem;">
                    @foreach ($perEvent as $row)
                        <div class="record-card record-card--facts" data-event-id="{{ $row['event_id'] }}">
                            <div class="record-card__body" style="width:100%;">
                                <span class="record-card__title">{{ $row['event_name'] }}</span>
                                <dl class="record-facts">
                                    <dt>Orders</dt><dd>{{ number_format($row['orders']) }}</dd>
                                    <dt>Tickets sold</dt><dd>{{ number_format($row['tickets_sold']) }}</dd>
                                    <dt>Gross sales</dt><dd>{{ $money($row['gross_sales_minor']) }}</dd>
                                    <dt>Booking fees</dt><dd>{{ $money($row['booking_fees_minor']) }}</dd>
                                    <dt>Platform fees</dt><dd>{{ $money($row['application_fees_minor']) }}</dd>
                                    <dt>Total collected</dt><dd>{{ $money($row['order_total_minor']) }}</dd>
                                    <dt>Stripe fees</dt><dd>{{ $money($row['stripe_fees_minor']) }}</dd>
                                    <dt>Net payout</dt><dd>{{ $money($row['net_payout_minor']) }}</dd>
                                </dl>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        @endif

        @if ($tab === 'attendance')
            <div class="panel">
                <div class="panel__head"><h2>Attendance &amp; check-in</h2></div>
                <p class="panel__note">
                    Attendance is reported per event once doors open. Open the live dashboard for scans as they
                    happen, or the attendance report for no-shows, check-ins by staff and the arrival timeline.
                </p>
                @if ($attendanceEvents->isEmpty())
                    <p class="table-empty-filter">No events yet.</p>
                @else
                    <div class="table-scroll">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th scope="col">Event</th>
                                    <th scope="col">Starts</th>
                                    <th scope="col"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($attendanceEvents as $attendanceEvent)
                                    <tr>
                                        <td class="cell-strong">{{ $attendanceEvent->name }}</td>
                                        <td>{{ $attendanceEvent->starts_at?->format('j M Y, H:i') ?? '—' }}</td>
                                        <td class="num">
                                            <a class="btn btn-sm btn-outline" href="{{ route('dashboard.events.live', $attendanceEvent) }}">Live dashboard</a>
                                            <a class="btn btn-sm btn-outline" href="{{ route('dashboard.events.attendance', $attendanceEvent) }}">Attendance report</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </section>
@endsection
