@extends('layouts.event')

@section('active_section', 'report')

@section('section')
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
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report->perTicketType as $row)
                        <tr>
                            <td><span class="cell-strong">{{ $row['name'] }}</span></td>
                            <td class="num">{{ number_format($row['sold']) }}</td>
                            <td class="num">{{ number_format($row['remaining']) }}</td>
                            <td class="num">{{ $money($row['revenue_minor']) }}</td>
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
