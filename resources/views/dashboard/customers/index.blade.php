@extends('layouts.dashboard')

@section('title', 'Customers')

@php use App\Http\Controllers\CustomerController; @endphp

@push('head')
<style>
    .filter-bar { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1.25rem; }
    .filter-bar .field { margin: 0; }
    .filter-bar input { padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 0.5rem; min-width: 260px; }
    .filter-bar label { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 0.25rem; }
    .pager { display: flex; justify-content: center; padding: 1rem; }
</style>
@endpush

@section('content')
    @php
        $currency = auth()->user()->company?->currency ?? 'GBP';
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $symbol = $symbols[$currency] ?? '';
    @endphp

    <div class="page-head">
        <h1>Customers</h1>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    <p class="muted">
        Everyone who has placed an order with you, identified by email. Open a
        customer to see their orders and, if you're the account owner, to export
        or erase their personal data for GDPR requests.
    </p>

    <form method="GET" action="{{ route('dashboard.customers.index') }}" class="filter-bar">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" name="q" id="q" value="{{ $search }}" placeholder="Name or email">
        </div>
        <button type="submit" class="btn">Search</button>
        @if ($search !== '')
            <a class="btn btn-outline" href="{{ route('dashboard.customers.index') }}">Clear</a>
        @endif
    </form>

    <div class="panel">
        @if ($customers->isEmpty())
            <div class="empty"><p>No customers yet.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col" class="num">Orders</th>
                        <th scope="col" class="num">Spend</th>
                        <th scope="col">Last order</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customers as $customer)
                        @php $token = CustomerController::tokenFor($customer->customer_email); @endphp
                        <tr>
                            <td>
                                <a class="cell-strong" href="{{ route('dashboard.customers.show', $token) }}">{{ $customer->customer_name }}</a>
                                <span class="cell-dim">{{ $customer->customer_email }}</span>
                            </td>
                            <td class="num">{{ number_format($customer->orders_count) }}</td>
                            <td class="num">{{ $symbol }}{{ number_format($customer->spend_minor / 100, 2) }}</td>
                            <td>{{ $customer->last_order_at ? \Illuminate\Support\Carbon::parse($customer->last_order_at)->format('j M Y, H:i') : '—' }}</td>
                            <td class="num"><a class="panel__link" href="{{ route('dashboard.customers.show', $token) }}">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="pager">{{ $customers->links() }}</div>
        @endif
    </div>
@endsection
