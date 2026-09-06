@extends('layouts.dashboard')

@section('title', 'Super-Admin — Dashboard')

@php
    // Amounts are aggregated across every Company. The Platform reports in its
    // base currency (GBP); per-Company currency detail lives on each client.
    $money = fn (int $minor) => '£' . number_format($minor / 100, 2);
@endphp

@section('content')
    <section>
        <h1>Platform dashboard</h1>

        <p class="muted">
            An at-a-glance overview across every Company on the Platform.
            Monetary totals are shown in the Platform base currency (GBP).
        </p>

        @if (session('status'))
            <p class="status" role="status">{{ session('status') }}</p>
        @endif

        <h2>Companies &amp; people</h2>
        <table>
            <tbody>
                <tr>
                    <th scope="row">Total companies</th>
                    <td data-metric="total_companies">{{ number_format($stats['total_companies']) }}</td>
                </tr>
                <tr>
                    <th scope="row">Active</th>
                    <td data-metric="active_companies">{{ number_format($stats['active_companies']) }}</td>
                </tr>
                <tr>
                    <th scope="row">Suspended</th>
                    <td data-metric="suspended_companies">{{ number_format($stats['suspended_companies']) }}</td>
                </tr>
                <tr>
                    <th scope="row">Total users</th>
                    <td data-metric="total_users">{{ number_format($stats['total_users']) }}</td>
                </tr>
            </tbody>
        </table>

        <h2>Events &amp; sales</h2>
        <table>
            <tbody>
                <tr>
                    <th scope="row">Total events</th>
                    <td data-metric="total_events">{{ number_format($stats['total_events']) }}</td>
                </tr>
                <tr>
                    <th scope="row">Published events</th>
                    <td data-metric="published_events">{{ number_format($stats['published_events']) }}</td>
                </tr>
                <tr>
                    <th scope="row">Confirmed orders</th>
                    <td data-metric="confirmed_orders">{{ number_format($stats['confirmed_orders']) }}</td>
                </tr>
                <tr>
                    <th scope="row">Gross sales</th>
                    <td data-metric="gross_sales_minor">{{ $money($stats['gross_sales_minor']) }}</td>
                </tr>
                <tr>
                    <th scope="row">Platform fees earned</th>
                    <td data-metric="platform_fees_minor">{{ $money($stats['platform_fees_minor']) }}</td>
                </tr>
            </tbody>
        </table>

        <h2>Newest companies</h2>
        @if ($recentCompanies->isEmpty())
            <p>No companies yet.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th scope="col">Company</th>
                        <th scope="col">Status</th>
                        <th scope="col">Confirmed orders</th>
                        <th scope="col">Gross sales</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentCompanies as $company)
                        <tr data-company-id="{{ $company->id }}">
                            <td>{{ $company->name }}</td>
                            <td data-status="{{ $company->status }}">{{ $company->status }}</td>
                            <td>{{ number_format($company->confirmed_orders_count) }}</td>
                            <td>{{ $money($company->gross_sales_minor) }}</td>
                            <td><a href="{{ route('admin.clients.show', $company) }}">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
