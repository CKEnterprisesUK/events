@extends('layouts.dashboard')

@section('title', 'Customer ' . $name)

@push('head')
<style>
    .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; padding: 1.25rem; }
    .detail__label { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
    .detail__value { font-weight: 600; color: var(--ink); }
    .back-link { display: inline-block; margin-bottom: 0.75rem; font-size: 0.9rem; }
    .gdpr-note { font-size: 0.85rem; color: var(--muted); padding: 0 1.25rem 1.25rem; }
    .gdpr-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; padding: 1.25rem; }
    .pill--paid, .pill--free_confirmed { background: #ecfdf5; color: #047857; }
    .pill--reserved { background: #eff6ff; color: #1d4ed8; }
    .pill--refunded, .pill--disputed { background: #fef2f2; color: #b91c1c; }
    .pill--cancelled, .pill--expired, .pill--voided { background: #f3f4f6; color: #6b7280; }
</style>
@endpush

@section('content')
    @php
        $currency = auth()->user()->company?->currency ?? 'GBP';
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $symbol = $symbols[$currency] ?? '';
        $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);
        $consents = $orders->flatMap->consents;
    @endphp

    <a class="back-link panel__link" href="{{ route('dashboard.customers.index') }}">&larr; Back to customers</a>

    <div class="page-head">
        <h1>{{ $name }}</h1>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    <div class="panel">
        <div class="detail-grid">
            <div>
                <div class="detail__label">Email</div>
                <div class="detail__value">{{ $email }}</div>
            </div>
            <div>
                <div class="detail__label">Orders</div>
                <div class="detail__value">{{ number_format($orders->count()) }}</div>
            </div>
            <div>
                <div class="detail__label">Confirmed spend</div>
                <div class="detail__value">{{ $money($confirmedSpendMinor) }}</div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h2>Orders</h2></div>
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col">Reference</th>
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
                        <td>{{ $order->event?->name ?? '—' }}</td>
                        <td>{{ $order->created_at?->format('j M Y, H:i') ?? '—' }}</td>
                        <td><span class="pill pill--{{ $order->status }}">{{ ucfirst(str_replace('_', ' ', $order->status)) }}</span></td>
                        <td class="num">{{ $money($order->order_total_minor) }}</td>
                        <td class="num"><a class="panel__link" href="{{ route('dashboard.orders.show', $order) }}">View</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="panel">
        <div class="panel__head"><h2>Consent records</h2></div>
        @if ($consents->isEmpty())
            <div class="empty"><p>No consent records captured for this customer.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Consent</th>
                        <th scope="col">Given</th>
                        <th scope="col">Order</th>
                        <th scope="col">When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($consents as $consent)
                        <tr>
                            <td>{{ ucfirst(str_replace('_', ' ', $consent->consent_key)) }}</td>
                            <td>{{ $consent->accepted ? 'Yes' : 'No' }}</td>
                            <td>{{ $consent->order?->order_reference ?? '—' }}</td>
                            <td>{{ $consent->captured_at?->format('j M Y, H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($canManageGdpr)
        <div class="panel">
            <div class="panel__head"><h2>Data &amp; GDPR</h2></div>
            <p class="gdpr-note">
                Export gives this customer a JSON copy of the personal data you
                hold about them. Erase anonymises their name and email across all
                their orders while keeping the transactional records (references,
                amounts, fees) you need for reconciliation. Erasing cannot be
                undone.
            </p>
            <div class="gdpr-actions">
                <form method="POST" action="{{ route('dashboard.customers.export', $token) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline">Export data (JSON)</button>
                </form>
                <form method="POST" action="{{ route('dashboard.customers.anonymise', $token) }}"
                      onsubmit="return confirm('Erase this customer\'s personal data across all their orders? This cannot be undone.');">
                    @csrf
                    <button type="submit" class="btn btn-danger">Erase personal data</button>
                </form>
            </div>
        </div>
    @endif
@endsection
