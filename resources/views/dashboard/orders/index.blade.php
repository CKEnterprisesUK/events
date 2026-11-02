@extends('layouts.dashboard')

@section('title', 'Orders')

@php use App\Http\Controllers\CustomerController; @endphp

@push('head')
<style>
    .filter-bar { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1.25rem; }
    .filter-bar .field { margin: 0; }
    .filter-bar input, .filter-bar select {
        padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 0.5rem; min-width: 220px;
    }
    .filter-bar label { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 0.25rem; }
    .pager { display: flex; justify-content: center; padding: 1rem; }
    /* Order-status pill colours are now provided by the shared dashboard theme
       layer in app.css (softly-filled badges). No local palette needed. */
    .orders-export { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
    .orders-export__label { font-size: 0.82rem; color: var(--muted); }
</style>
@endpush

{{-- Renders its own .page-header <h1>; mobile_context feeds the mobile top-bar
     label without producing a duplicate heading. --}}
@section('mobile_context', 'Orders')

@section('content')
    <div class="page-header">
        <div class="page-header__text">
            <h1>Orders</h1>
            <p class="page-header__desc">Every order across your events. Search or filter to narrow the list.</p>
        </div>
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
            <label for="event">Event</label>
            <select name="event" id="event">
                <option value="">All events</option>
                @foreach ($events as $id => $name)
                    <option value="{{ $id }}" @selected((string) $eventId === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
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
        <div class="field">
            <label for="from">From</label>
            <input type="date" name="from" id="from" value="{{ $from }}">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input type="date" name="to" id="to" value="{{ $to }}">
        </div>
        <button type="submit" class="btn">Filter</button>
        @if ($search !== '' || $status !== '' || $eventId !== null || $from || $to)
            <a class="btn btn-outline" href="{{ route('dashboard.orders.index') }}">Clear</a>
        @endif
    </form>

    @php
        // Carry the active filters through to the export links so the file
        // matches exactly what is on screen.
        $exportQuery = array_filter([
            'q' => $search,
            'event' => $eventId,
            'status' => $status,
            'from' => $from,
            'to' => $to,
        ], fn ($v) => $v !== null && $v !== '');
    @endphp
    <div class="orders-export">
        <span class="orders-export__label">Export{{ $orders->total() > 0 ? ' '.number_format($orders->total()).' '.\Illuminate\Support\Str::plural('order', $orders->total()) : '' }}:</span>
        <a class="btn btn-outline btn-sm" href="{{ route('dashboard.orders.export', $exportQuery) }}" download>CSV</a>
        <a class="btn btn-outline btn-sm" href="{{ route('dashboard.orders.export', array_merge($exportQuery, ['format' => 'xlsx'])) }}" download>Excel</a>
    </div>

    <div class="panel">
        @if ($orders->isEmpty())
            <div class="empty"><p>No orders match your filters.</p></div>
        @else
            {{-- Desktop: full table. --}}
            <table class="data-table only-desktop">
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Event</th>
                        <th scope="col">Placed</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Total</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr class="row-nav">
                            <td><a class="cell-strong cell-ref row-link" href="{{ route('dashboard.orders.show', $order) }}">{{ $order->order_reference }}</a></td>
                            <td>
                                @if ($order->customer_email)
                                    <a class="cell-strong row-action" href="{{ route('dashboard.customers.show', CustomerController::tokenFor($order->customer_email)) }}">{{ $order->customer_name }}</a>
                                @else
                                    <span class="cell-strong">{{ $order->customer_name }}</span>
                                @endif
                                <span class="cell-dim">{{ $order->customer_email }}</span>
                            </td>
                            <td>{{ $order->event?->name ?? '—' }}</td>
                            <td>{{ $order->created_at?->format('j M Y, H:i') ?? '—' }}</td>
                            <td><span class="pill pill--{{ $order->status }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span></td>
                            <td class="num">£{{ number_format($order->order_total_minor / 100, 2) }}</td>
                            <td class="num">
                                <a class="row-go row-action" href="{{ route('dashboard.orders.show', $order) }}" aria-label="Open order {{ $order->order_reference }}">
                                    <x-icon name="chevron" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Mobile: stacked order records. --}}
            <div class="only-mobile record-list" style="padding: 1rem 1.25rem;">
                @foreach ($orders as $order)
                    <div class="record-card row-nav">
                        <div class="record-card__body">
                            <a class="record-card__title cell-strong cell-ref row-link" href="{{ route('dashboard.orders.show', $order) }}">{{ $order->order_reference }}</a>
                            <span class="record-card__meta record-card__meta--strong">
                                @if ($order->customer_email)
                                    <a class="row-action" href="{{ route('dashboard.customers.show', CustomerController::tokenFor($order->customer_email)) }}">{{ $order->customer_name }}</a>
                                @else
                                    {{ $order->customer_name }}
                                @endif
                            </span>
                            <span class="record-card__meta">{{ $order->customer_email }}</span>
                            <span class="record-card__meta">{{ $order->event?->name ?? '—' }} · {{ $order->created_at?->format('j M Y, H:i') ?? '—' }}</span>
                            <span class="record-card__meta">
                                <span class="pill pill--{{ $order->status }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span>
                                · £{{ number_format($order->order_total_minor / 100, 2) }}
                            </span>
                        </div>
                        <a class="btn btn-outline btn-sm record-card__action row-action" href="{{ route('dashboard.orders.show', $order) }}">View</a>
                    </div>
                @endforeach
            </div>
            <div class="pager">{{ $orders->links() }}</div>
        @endif
    </div>
@endsection
