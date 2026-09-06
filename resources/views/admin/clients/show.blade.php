@extends('layouts.dashboard')

@section('title', 'Super-Admin — ' . $company->name)

@php use App\Support\Money; @endphp

@section('content')
    <div class="admin-head">
        <div>
            <a class="admin-back" href="{{ route('admin.clients.index') }}">&#8592; Back to clients</a>
            <h1>{{ $company->name }}</h1>
            <p>
                <span class="admin-pill admin-pill--{{ $company->status }}">{{ $company->status }}</span>
                &nbsp;/{{ $company->slug }}
            </p>
        </div>
        <div class="admin-head__actions">
            @if (! $company->isSuspended())
                <form method="POST" action="{{ route('admin.impersonate.start', $company) }}">
                    @csrf
                    <button type="submit" class="btn">Impersonate</button>
                </form>
            @endif
            <a class="btn btn-outline" href="{{ url('/' . $company->slug) }}" target="_blank" rel="noopener">View storefront</a>
            @if ($company->isSuspended())
                <form method="POST" action="{{ route('admin.companies.unsuspend', $company) }}">
                    @csrf
                    <button type="submit" class="btn">Unsuspend</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.companies.suspend', $company) }}">
                    @csrf
                    <button type="submit" class="btn btn-danger">Suspend</button>
                </form>
            @endif
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    @error('company')
        <p class="status status--error" role="alert">{{ $message }}</p>
    @enderror

    <div class="admin-stats">
        <div class="admin-stat">
            <span class="admin-stat__label">Events</span>
            <span class="admin-stat__value" data-metric="total_events">{{ number_format($stats['total_events']) }}</span>
            <span class="admin-stat__sub">{{ number_format($stats['published_events']) }} published</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Confirmed orders</span>
            <span class="admin-stat__value" data-metric="confirmed_orders">{{ number_format($stats['confirmed_orders']) }}</span>
            <span class="admin-stat__sub">{{ number_format($stats['tickets_sold']) }} tickets sold</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Gross sales</span>
            <span class="admin-stat__value" data-metric="gross_sales_minor">{{ Money::gbp($stats['gross_sales_minor']) }}</span>
            <span class="admin-stat__sub">Ticket subtotal</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Platform fees</span>
            <span class="admin-stat__value" data-metric="platform_fees_minor">{{ Money::gbp($stats['platform_fees_minor']) }}</span>
            <span class="admin-stat__sub">Earned from this client</span>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Profile</h2></div>
        <table class="admin-facts">
            <tbody>
                <tr><th scope="row">Legal name</th><td>{{ $company->legal_name ?? '—' }}</td></tr>
                <tr><th scope="row">Organisation type</th><td>{{ \App\Models\Company::ORGANISATION_TYPES[$company->organisation_type] ?? '—' }}</td></tr>
                <tr><th scope="row">Email</th><td>{{ $company->email ?? '—' }}</td></tr>
                <tr><th scope="row">Trading currency</th><td>{{ $company->currency }}</td></tr>
                <tr><th scope="row">Payments enabled</th><td>{{ $company->canAcceptPayments() ? 'Yes' : 'No' }}</td></tr>
                <tr><th scope="row">Team members</th><td>{{ number_format($stats['team_members']) }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Financials</h2></div>
        <table class="admin-facts">
            <tbody>
                <tr><th scope="row">Gross sales</th><td data-metric="gross_sales_minor">{{ Money::gbp($stats['gross_sales_minor']) }}</td></tr>
                <tr><th scope="row">Booking fees</th><td data-metric="booking_fees_minor">{{ Money::gbp($stats['booking_fees_minor']) }}</td></tr>
                <tr><th scope="row">Platform fees earned</th><td data-metric="platform_fees_minor">{{ Money::gbp($stats['platform_fees_minor']) }}</td></tr>
                <tr><th scope="row">Order total (incl. fees)</th><td data-metric="order_total_minor">{{ Money::gbp($stats['order_total_minor']) }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Recent events</h2></div>
        @if ($recentEvents->isEmpty())
            <div class="admin-empty">No events yet.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">When</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Confirmed orders</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentEvents as $event)
                        <tr data-event-id="{{ $event->id }}">
                            <td><span class="cell-strong">{{ $event->name }}</span></td>
                            <td>{{ $event->starts_at ? $event->starts_at->format('j M Y, H:i') : '—' }}</td>
                            <td><span class="pill {{ $event->is_published ? 'pill--live' : 'pill--draft' }}">{{ $event->is_published ? 'Published' : 'Draft' }}</span></td>
                            <td class="num">{{ number_format($event->confirmed_orders_count) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Recent support requests</h2></div>
        @if ($recentSupportRequests->isEmpty())
            <div class="admin-empty">No support requests from this client.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Status</th>
                        <th scope="col">Subject</th>
                        <th scope="col">Raised by</th>
                        <th scope="col">Raised</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentSupportRequests as $ticket)
                        <tr data-ticket-id="{{ $ticket->id }}">
                            <td>
                                <span class="pill {{ $ticket->isClosed() ? 'pill--live' : 'pill--draft' }}">{{ $ticket->statusLabel() }}</span>
                            </td>
                            <td><a href="{{ route('admin.support.show', $ticket->id) }}" class="cell-strong">{{ $ticket->subject }}</a></td>
                            <td>{{ $ticket->user?->name ?? 'Unknown' }}</td>
                            <td>{{ optional($ticket->created_at)->format('j M Y, H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
