@extends('layouts.event')

@section('active_section', 'report')

@section('section')
    <p class="hint" style="margin-bottom:1rem;">
        Looking for who showed up?
        <a href="{{ route('dashboard.events.attendance', $event) }}">See the attendance &amp; check-in report</a>
        or the <a href="{{ route('dashboard.events.live', $event) }}">live dashboard</a>.
    </p>

    @includeWhen(isset($range), 'dashboard.events._report_date_filter', ['action' => 'dashboard.events.report'])

    @php
        $currency = $event->company->currency ?? 'GBP';
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $sym = $symbols[$currency] ?? '';
        $money = fn (int $minor) => $sym . number_format($minor / 100, 2);
    @endphp

    {{-- Headline figures as a small summary row. --}}
    <div class="summary-row">
        <div class="metric-card">
            <span class="metric-card__label">Tickets sold</span>
            <span class="metric-card__value" data-metric="tickets_sold">{{ number_format($report->ticketsSold) }}</span>
        </div>
        <div class="metric-card">
            <span class="metric-card__label">Confirmed orders</span>
            <span class="metric-card__value" data-metric="confirmed_orders">{{ number_format($report->confirmedOrders) }}</span>
        </div>
        <div class="metric-card">
            <span class="metric-card__label">Gross revenue</span>
            <span class="metric-card__value" data-metric="gross_revenue_minor">{{ $money($report->grossRevenueMinor) }}</span>
        </div>
        <div class="metric-card">
            <span class="metric-card__label">Revenue per order</span>
            <span class="metric-card__value" data-metric="revenue_per_order_minor">{{ $money($report->confirmedOrders > 0 ? intdiv($report->grossRevenueMinor, $report->confirmedOrders) : 0) }}</span>
        </div>
        <div class="metric-card">
            <span class="metric-card__label">Net to company</span>
            <span class="metric-card__value" data-metric="net_to_company_minor">{{ $money($report->netToCompanyMinor) }}</span>
        </div>
        <div class="metric-card">
            <span class="metric-card__label">Capacity used</span>
            <span class="metric-card__value" data-metric="utilisation">
                @if (is_string($report->utilisation()))&infin;@else{{ $report->utilisation() }}%@endif
            </span>
        </div>
    </div>

    {{-- Per-ticket-type breakdown (Requirement 6.3). --}}
    <div class="panel">
        <div class="panel__head"><h2>By ticket type</h2></div>
        @if (empty($report->perTicketType))
            <div class="empty"><p>No ticket types yet.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Ticket type</th>
                        <th scope="col" class="num">Sold</th>
                        <th scope="col" class="num">Remaining</th>
                        <th scope="col" class="num">Revenue</th>
                        <th scope="col" class="num">Share</th>
                        <th scope="col">Sell-through</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report->perTicketType as $row)
                        @php $insight = $ticketTypeInsights[$row['type_id']] ?? null; @endphp
                        <tr>
                            <td><span class="cell-strong">{{ $row['name'] }}</span></td>
                            <td class="num">{{ number_format($row['sold']) }}</td>
                            <td class="num">{{ $row['remaining'] === null ? '—' : number_format($row['remaining']) }}</td>
                            <td class="num">{{ $money($row['revenue_minor']) }}</td>
                            <td class="num">{{ $insight ? rtrim(rtrim(number_format($insight['share_percent'], 1), '0'), '.') : 0 }}%</td>
                            <td>
                                @if (($insight['sell_through_percent'] ?? null) === null)
                                    <span class="cell-dim">No fixed allocation</span>
                                @else
                                    <span class="sell-through {{ $insight['sell_through_percent'] >= 100 ? 'is-sold-out' : ($insight['sell_through_percent'] >= 90 ? 'is-almost-full' : '') }}">
                                        <span class="sell-through__bar" role="img"
                                              aria-label="{{ $insight['sell_through_percent'] }} percent of {{ number_format($insight['capacity']) }} allocated">
                                            <span class="sell-through__fill" style="width: {{ min(100, $insight['sell_through_percent']) }}%"></span>
                                        </span>
                                        <span class="sell-through__meta">{{ rtrim(rtrim(number_format($insight['sell_through_percent'], 1), '0'), '.') }}% of {{ number_format($insight['capacity']) }}@if ($insight['sell_through_percent'] >= 100) &middot; Sold out @endif</span>
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Orders by status (Requirement 6.4). --}}
    <div class="panel">
        <div class="panel__head"><h2>Orders by status</h2></div>
        <table class="data-table">
            <tbody>
                @foreach (['paid' => 'Paid', 'reserved' => 'Reserved', 'refunded' => 'Refunded', 'cancelled' => 'Cancelled', 'comp' => 'Complimentary'] as $key => $label)
                    <tr>
                        <th scope="row"><span class="cell-strong">{{ $label }}</span></th>
                        <td class="num" data-status="{{ $key }}">{{ number_format($report->ordersByStatus[$key] ?? 0) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <th scope="row"><span class="cell-strong">Amount refunded</span></th>
                    <td class="num" data-metric="refunded_minor">{{ $money($refunds['amount_minor']) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Sales over time, grouped by fulfilment day (Requirement 6.5). --}}
    <div class="panel">
        <div class="panel__head"><h2>Sales over time</h2></div>
        @if (empty($report->salesByDay))
            <div class="empty"><p>No sales yet.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Day</th>
                        <th scope="col" class="num">Tickets</th>
                        <th scope="col" class="num">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report->salesByDay as $row)
                        <tr>
                            <td><span class="cell-strong">{{ $row['day'] }}</span></td>
                            <td class="num">{{ number_format($row['tickets']) }}</td>
                            <td class="num">{{ $money($row['revenue_minor']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
