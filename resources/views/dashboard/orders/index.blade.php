@extends('layouts.dashboard')

@section('title', 'Orders')

@push('head')
<style>
    .filter-bar { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1.25rem; }
    .filter-bar .field { margin: 0; }
    .filter-bar input, .filter-bar select {
        padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 0.5rem; min-width: 220px;
    }
    .filter-bar label { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 0.25rem; }
    .pager { display: flex; justify-content: center; padding: 1rem; }
    .pill--paid, .pill--free_confirmed { background: #ecfdf5; color: #047857; }
    .pill--reserved { background: #eff6ff; color: #1d4ed8; }
    .pill--refunded, .pill--disputed { background: #fef2f2; color: #b91c1c; }
    .pill--cancelled, .pill--expired, .pill--voided { background: #f3f4f6; color: #6b7280; }
</style>
@endpush

@section('content')
    <div class="page-head">
        <h1>Orders</h1>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    <form method="GET" action="{{ route('dashboard.orders.index') }}" class="filter-bar">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" name="q" id="q" value="{{ $search }}" placeholder="Reference, name or email">
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select name="status" id="status">
                <option value="">All statuses</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn">Filter</button>
        @if ($search !== '' || $status !== '')
            <a class="btn btn-outline" href="{{ route('dashboard.orders.index') }}">Clear</a>
        @endif
    </form>

    <div class="panel">
        @if ($orders->isEmpty())
            <div class="empty"><p>No orders match your filters.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Event</th>
                        <th scope="col">Placed</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Total</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td><a class="cell-strong" href="{{ route('dashboard.orders.show', $order) }}">{{ $order->order_reference }}</a></td>
                            <td>
                                {{ $order->customer_name }}
                                <span class="cell-dim">{{ $order->customer_email }}</span>
                            </td>
                            <td>{{ $order->event?->name ?? '—' }}</td>
                            <td>{{ $order->created_at?->format('j M Y, H:i') ?? '—' }}</td>
                            <td><span class="pill pill--{{ $order->status }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span></td>
                            <td class="num">{{ number_format($order->order_total_minor / 100, 2) }}</td>
                            <td class="num"><a class="panel__link" href="{{ route('dashboard.orders.show', $order) }}">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="pager">{{ $orders->links() }}</div>
        @endif
    </div>
@endsection
