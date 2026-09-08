@extends('layouts.dashboard')

@section('title', $event->name.': Report')

@section('content')
    <div class="page-head">
        <div>
            <p class="dash-eyebrow"><a class="panel__link" href="{{ route('dashboard.events.show', $event) }}">&larr; {{ $event->name }}</a></p>
            <h1>Event report</h1>
        </div>
    </div>

    {{-- Core metrics (Requirement 6.2). Money is stored in minor units, so it is
         rendered as major units with two decimals — matching the rest of the
         dashboard. Capacity utilisation is either the string "unlimited" (no
         fixed ceiling) or a percentage. --}}
    <div class="panel">
        <div class="panel__head"><h2>Overview</h2></div>
        <table class="data-table">
            <tbody>
                <tr>
                    <td><span class="cell-strong">Tickets sold</span></td>
                    <td class="num" data-metric="tickets_sold">{{ number_format($report->ticketsSold) }}</td>
                </tr>
                <tr>
                    <td><span class="cell-strong">Gross revenue</span></td>
                    <td class="num" data-metric="gross_revenue_minor">{{ number_format($report->grossRevenueMinor / 100, 2) }}</td>
                </tr>
                <tr>
                    <td><span class="cell-strong">Net to company</span></td>
                    <td class="num" data-metric="net_to_company_minor">{{ number_format($report->netToCompanyMinor / 100, 2) }}</td>
                </tr>
                <tr>
                    <td><span class="cell-strong">Capacity utilisation</span></td>
                    <td class="num" data-metric="utilisation">
                        @if (is_string($report->utilisation()))
                            Unlimited
                        @else
                            {{ $report->utilisation() }}%
                        @endif
                    </td>
                </tr>
                <tr>
                    <td><span class="cell-strong">Confirmed orders</span></td>
                    <td class="num" data-metric="confirmed_orders">{{ number_format($report->confirmedOrders) }}</td>
                </tr>
            </tbody>
        </table>
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
                        <th>Ticket type</th>
                        <th class="num">Sold</th>
                        <th class="num">Remaining</th>
                        <th class="num">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report->perTicketType as $row)
                        <tr>
                            <td><span class="cell-strong">{{ $row['name'] }}</span></td>
                            <td class="num">{{ number_format($row['sold']) }}</td>
                            <td class="num">{{ number_format($row['remaining']) }}</td>
                            <td class="num">{{ number_format($row['revenue_minor'] / 100, 2) }}</td>
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
                        <td><span class="cell-strong">{{ $label }}</span></td>
                        <td class="num" data-status="{{ $key }}">{{ number_format($report->ordersByStatus[$key] ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Sales over time, grouped by fulfilment day (Requirement 6.5).
         A simple table is sufficient — no charting library. --}}
    <div class="panel">
        <div class="panel__head"><h2>Sales over time</h2></div>
        @if (empty($report->salesByDay))
            <div class="empty"><p>No sales yet.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th class="num">Tickets</th>
                        <th class="num">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report->salesByDay as $row)
                        <tr>
                            <td><span class="cell-strong">{{ $row['day'] }}</span></td>
                            <td class="num">{{ number_format($row['tickets']) }}</td>
                            <td class="num">{{ number_format($row['revenue_minor'] / 100, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
