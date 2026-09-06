@extends('layouts.dashboard')

@section('title', 'Super-Admin — ' . $company->name)

@php
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$company->currency] ?? '';
    $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);
@endphp

@section('content')
    <section>
        <p class="muted"><a href="{{ route('admin.clients.index') }}">&#8592; Back to clients</a></p>

        <h1>{{ $company->name }}</h1>

        <p class="muted">
            /{{ $company->slug }} &middot; {{ ucfirst($company->status) }} &middot;
            {{ $company->currency }}
        </p>

        @if (session('status'))
            <p class="status" role="status">{{ session('status') }}</p>
        @endif

        <h2>Profile</h2>
        <table>
            <tbody>
                <tr>
                    <th scope="row">Legal name</th>
                    <td>{{ $company->legal_name ?? '—' }}</td>
                </tr>
                <tr>
                    <th scope="row">Organisation type</th>
                    <td>{{ \App\Models\Company::ORGANISATION_TYPES[$company->organisation_type] ?? '—' }}</td>
                </tr>
                <tr>
                    <th scope="row">Email</th>
                    <td>{{ $company->email ?? '—' }}</td>
                </tr>
                <tr>
                    <th scope="row">Payments enabled</th>
                    <td>{{ $company->canAcceptPayments() ? 'Yes' : 'No' }}</td>
                </tr>
                <tr>
                    <th scope="row">Team members</th>
                    <td>{{ number_format($stats['team_members']) }}</td>
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
                    <th scope="row">Tickets sold</th>
                    <td data-metric="tickets_sold">{{ number_format($stats['tickets_sold']) }}</td>
                </tr>
                <tr>
                    <th scope="row">Gross sales</th>
                    <td data-metric="gross_sales_minor">{{ $money($stats['gross_sales_minor']) }}</td>
                </tr>
                <tr>
                    <th scope="row">Booking fees</th>
                    <td data-metric="booking_fees_minor">{{ $money($stats['booking_fees_minor']) }}</td>
                </tr>
                <tr>
                    <th scope="row">Platform fees earned</th>
                    <td data-metric="platform_fees_minor">{{ $money($stats['platform_fees_minor']) }}</td>
                </tr>
                <tr>
                    <th scope="row">Order total (incl. fees)</th>
                    <td data-metric="order_total_minor">{{ $money($stats['order_total_minor']) }}</td>
                </tr>
            </tbody>
        </table>

        <h2>Recent events</h2>
        @if ($recentEvents->isEmpty())
            <p>No events yet.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">When</th>
                        <th scope="col">Status</th>
                        <th scope="col">Confirmed orders</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentEvents as $event)
                        <tr data-event-id="{{ $event->id }}">
                            <td>{{ $event->name }}</td>
                            <td>{{ $event->starts_at ? $event->starts_at->format('j M Y, H:i') : '—' }}</td>
                            <td>{{ $event->is_published ? 'Published' : 'Draft' }}</td>
                            <td>{{ number_format($event->confirmed_orders_count) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
