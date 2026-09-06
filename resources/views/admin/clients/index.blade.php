@extends('layouts.dashboard')

@section('title', 'Super-Admin — Clients')

@php
    $money = fn (int $minor) => '£' . number_format($minor / 100, 2);
@endphp

@section('content')
    <section>
        <h1>Clients</h1>

        <p class="muted">
            Every Company on the Platform with its headline stats. Monetary
            totals are shown in the Platform base currency (GBP).
        </p>

        @if (session('status'))
            <p class="status" role="status">{{ session('status') }}</p>
        @endif

        @if ($companies->isEmpty())
            <p>No companies yet.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th scope="col">Company</th>
                        <th scope="col">Slug</th>
                        <th scope="col">Status</th>
                        <th scope="col">Events</th>
                        <th scope="col">Confirmed orders</th>
                        <th scope="col">Gross sales</th>
                        <th scope="col">Platform fees</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($companies as $company)
                        <tr data-company-id="{{ $company->id }}">
                            <td>{{ $company->name }}</td>
                            <td>{{ $company->slug }}</td>
                            <td data-status="{{ $company->status }}">{{ $company->status }}</td>
                            <td>{{ number_format($company->events_count) }}</td>
                            <td>{{ number_format($company->confirmed_orders_count) }}</td>
                            <td>{{ $money($company->gross_sales_minor) }}</td>
                            <td>{{ $money($company->platform_fees_minor) }}</td>
                            <td><a href="{{ route('admin.clients.show', $company) }}">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
