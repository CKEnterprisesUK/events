@extends('layouts.dashboard')

@section('title', 'Order ' . $order->order_reference)

@push('head')
<style>
    .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; padding: 1.25rem; }
    .detail__label { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
    .detail__value { font-weight: 600; color: var(--ink); }
    .money-table { width: 100%; border-collapse: collapse; }
    .money-table td { padding: 0.5rem 1.25rem; }
    .money-table td.num { text-align: right; }
    .money-table tr.total td { border-top: 1px solid var(--border); font-weight: 700; }
    .pill--paid, .pill--free_confirmed { background: #ecfdf5; color: #047857; }
    .pill--reserved { background: #eff6ff; color: #1d4ed8; }
    .pill--refunded, .pill--disputed { background: #fef2f2; color: #b91c1c; }
    .pill--cancelled, .pill--expired, .pill--voided { background: #f3f4f6; color: #6b7280; }
    .back-link { display: inline-block; margin-bottom: 0.75rem; font-size: 0.9rem; }
</style>
@endpush

@section('content')
    @php
        $currency = $order->event?->company?->currency ?? auth()->user()->company?->currency ?? 'GBP';
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $symbol = $symbols[$currency] ?? '';
        $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);
    @endphp

    <a class="back-link panel__link" href="{{ route('dashboard.orders.index') }}">&larr; Back to orders</a>

    <div class="page-head">
        <h1>{{ $order->order_reference }}</h1>
        <div class="page-head__actions">
            @unless ($terminal)
                @can('refund_order')
                    @if ($order->status === \App\Models\Order::STATUS_PAID)
                        <form method="POST" action="{{ route('dashboard.orders.refund', $order) }}" class="inline-form"
                              onsubmit="return confirm('Refund this order? This issues a Stripe refund and voids the tickets.');">
                            @csrf
                            <button type="submit" class="btn btn-danger">Refund</button>
                        </form>
                    @endif
                @endcan
                @can('cancel_order')
                    <form method="POST" action="{{ route('dashboard.orders.cancel', $order) }}" class="inline-form"
                          onsubmit="return confirm('Cancel this order? This voids the tickets and releases capacity.');">
                        @csrf
                        <button type="submit" class="btn btn-outline">Cancel</button>
                    </form>
                @endcan
            @endunless
        </div>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    <div class="panel">
        <div class="detail-grid">
            <div>
                <div class="detail__label">Status</div>
                <div class="detail__value"><span class="pill pill--{{ $order->status }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span></div>
            </div>
            <div>
                <div class="detail__label">Customer</div>
                <div class="detail__value">{{ $order->customer_name }}</div>
                <div class="cell-dim">{{ $order->customer_email }}</div>
            </div>
            <div>
                <div class="detail__label">Event</div>
                <div class="detail__value">
                    @if ($order->event)
                        <a class="panel__link" href="{{ route('dashboard.events.show', $order->event) }}">{{ $order->event->name }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div>
                <div class="detail__label">Placed</div>
                <div class="detail__value">{{ $order->created_at?->format('j M Y, H:i') ?? '—' }}</div>
            </div>
            <div>
                <div class="detail__label">Check-in</div>
                <div class="detail__value">
                    @if ($order->scanned_at)
                        Scanned {{ $order->scanned_at->format('j M Y, H:i') }}
                    @else
                        Not yet scanned
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h2>Payment</h2></div>
        <table class="money-table">
            <tbody>
                <tr><td>Ticket subtotal</td><td class="num">{{ $money($order->ticket_subtotal_minor) }}</td></tr>
                <tr><td>Booking fee</td><td class="num">{{ $money($order->booking_fee_minor) }}</td></tr>
                <tr class="total"><td>Order total</td><td class="num">{{ $money($order->order_total_minor) }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="panel">
        <div class="panel__head"><h2>Tickets ({{ $order->tickets->count() }})</h2></div>
        @if ($order->tickets->isEmpty())
            <div class="empty"><p>No tickets on this order.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Ticket type</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->tickets as $ticket)
                        <tr>
                            <td>{{ $ticket->ticketType?->name ?? '—' }}</td>
                            <td><span class="pill {{ $ticket->status === \App\Models\Ticket::STATUS_VALID ? 'pill--live' : 'pill--voided' }}">{{ ucfirst($ticket->status) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="panel">
        <div class="panel__head"><h2>Consent records</h2></div>
        @if ($order->consents->isEmpty())
            <div class="empty"><p>No consent records captured for this order.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Consent</th>
                        <th scope="col">Given</th>
                        <th scope="col">When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($order->consents as $consent)
                        <tr>
                            <td>{{ ucfirst(str_replace('_', ' ', $consent->consent_key)) }}</td>
                            <td>{{ $consent->accepted ? 'Yes' : 'No' }}</td>
                            <td>{{ $consent->captured_at?->format('j M Y, H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
