@extends('layouts.app')

@section('title', 'Reports & Payouts')

@section('content')
    <section>
        <h1>Reports &amp; Payouts</h1>

        <p class="muted">
            Read-only view of your Company's realised sales from paid and
            confirmed orders. Amounts are shown in minor currency units.
        </p>

        <h2>Company totals</h2>
        <table>
            <tbody>
                <tr>
                    <th scope="row">Confirmed orders</th>
                    <td data-metric="orders">{{ $totals['orders'] }}</td>
                </tr>
                <tr>
                    <th scope="row">Tickets sold</th>
                    <td data-metric="tickets_sold">{{ $totals['tickets_sold'] }}</td>
                </tr>
                <tr>
                    <th scope="row">Gross sales</th>
                    <td data-metric="gross_sales_minor">{{ $totals['gross_sales_minor'] }}</td>
                </tr>
                <tr>
                    <th scope="row">Booking fees collected</th>
                    <td data-metric="booking_fees_minor">{{ $totals['booking_fees_minor'] }}</td>
                </tr>
                <tr>
                    <th scope="row">Platform fees</th>
                    <td data-metric="application_fees_minor">{{ $totals['application_fees_minor'] }}</td>
                </tr>
                <tr>
                    <th scope="row">Total collected</th>
                    <td data-metric="order_total_minor">{{ $totals['order_total_minor'] }}</td>
                </tr>
                <tr>
                    <th scope="row">Net to company (payout)</th>
                    <td data-metric="net_to_company_minor">{{ $totals['net_to_company_minor'] }}</td>
                </tr>
            </tbody>
        </table>

        <p class="muted">
            Funds settle directly to your connected Stripe account. The platform
            fee is deducted at the point of sale, so the net-to-company figure is
            what reaches your account.
        </p>

        <h2>Per-event breakdown</h2>
        @if (empty($perEvent))
            <p>No confirmed sales yet.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">Orders</th>
                        <th scope="col">Tickets sold</th>
                        <th scope="col">Gross sales</th>
                        <th scope="col">Booking fees</th>
                        <th scope="col">Platform fees</th>
                        <th scope="col">Total collected</th>
                        <th scope="col">Net to company</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($perEvent as $row)
                        <tr data-event-id="{{ $row['event_id'] }}">
                            <td>{{ $row['event_name'] }}</td>
                            <td data-metric="orders">{{ $row['orders'] }}</td>
                            <td data-metric="tickets_sold">{{ $row['tickets_sold'] }}</td>
                            <td data-metric="gross_sales_minor">{{ $row['gross_sales_minor'] }}</td>
                            <td data-metric="booking_fees_minor">{{ $row['booking_fees_minor'] }}</td>
                            <td data-metric="application_fees_minor">{{ $row['application_fees_minor'] }}</td>
                            <td data-metric="order_total_minor">{{ $row['order_total_minor'] }}</td>
                            <td data-metric="net_to_company_minor">{{ $row['net_to_company_minor'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
