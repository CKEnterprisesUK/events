@extends('layouts.dashboard')

@section('title', 'Super-Admin — Dashboard')

@php use App\Support\Money; @endphp

@section('content')
    <div class="admin-head">
        <div>
            <h1>Platform dashboard</h1>
            <p>An at-a-glance overview across every company on the platform. All amounts are in GBP.</p>
        </div>
        <div class="admin-head__actions">
            <a class="btn" href="{{ route('admin.clients.index') }}">View clients</a>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-stats">
        <div class="admin-stat">
            <span class="admin-stat__label">Companies</span>
            <span class="admin-stat__value" data-metric="total_companies">{{ number_format($stats['total_companies']) }}</span>
            <span class="admin-stat__sub">{{ number_format($stats['active_companies']) }} active &middot; {{ number_format($stats['suspended_companies']) }} suspended</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Users</span>
            <span class="admin-stat__value" data-metric="total_users">{{ number_format($stats['total_users']) }}</span>
            <span class="admin-stat__sub">Across all companies</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Events</span>
            <span class="admin-stat__value" data-metric="total_events">{{ number_format($stats['total_events']) }}</span>
            <span class="admin-stat__sub">{{ number_format($stats['published_events']) }} published</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Confirmed orders</span>
            <span class="admin-stat__value" data-metric="confirmed_orders">{{ number_format($stats['confirmed_orders']) }}</span>
            <span class="admin-stat__sub">Paid &amp; free-confirmed</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Gross sales</span>
            <span class="admin-stat__value" data-metric="gross_sales_minor">{{ Money::gbp($stats['gross_sales_minor']) }}</span>
            <span class="admin-stat__sub">Ticket subtotal</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Platform fees earned</span>
            <span class="admin-stat__value" data-metric="platform_fees_minor">{{ Money::gbp($stats['platform_fees_minor']) }}</span>
            <span class="admin-stat__sub">Realised application fees</span>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head">
            <h2>Newest companies</h2>
            <a class="panel__link" href="{{ route('admin.clients.index') }}">View all clients</a>
        </div>
        @if ($recentCompanies->isEmpty())
            <div class="admin-empty">No companies yet.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Company</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Confirmed orders</th>
                        <th scope="col" class="num">Gross sales</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentCompanies as $company)
                        <tr data-company-id="{{ $company->id }}">
                            <td>
                                <a class="cell-strong" href="{{ route('admin.clients.show', $company) }}">{{ $company->name }}</a>
                                <span class="cell-dim">/{{ $company->slug }}</span>
                            </td>
                            <td><span class="admin-pill admin-pill--{{ $company->status }}" data-status="{{ $company->status }}">{{ $company->status }}</span></td>
                            <td class="num">{{ number_format($company->confirmed_orders_count) }}</td>
                            <td class="num">{{ Money::gbp($company->gross_sales_minor) }}</td>
                            <td class="num"><a class="panel__link" href="{{ route('admin.clients.show', $company) }}">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
