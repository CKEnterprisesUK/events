@extends('layouts.dashboard')

@section('title', 'Customer ' . $name)

@push('head')
<style>
    .profile { display: flex; gap: 1.25rem; align-items: center; flex-wrap: wrap; padding: 1.25rem; }
    .profile__avatar { flex: none; width: 4rem; height: 4rem; border-radius: 50%; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; font-weight: 700; }
    .profile__main { flex: 1 1 14rem; min-width: 0; }
    .profile__name { margin: 0; font-size: 1.35rem; overflow-wrap: anywhere; }
    .profile__email { color: var(--muted); overflow-wrap: anywhere; }
    .profile__stats { display: flex; gap: 2rem; flex-wrap: wrap; }
    .profile__retention { border-top: 1px solid var(--border); padding: 0.85rem 1.25rem; font-size: 0.85rem; color: var(--muted); }
    .tab-count { font-size: 0.75rem; background: #f3f4f6; color: var(--muted); border-radius: 999px; padding: 0 0.45rem; }
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

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    <div class="panel">
        <div class="profile">
            <div class="profile__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($name) ?: '?', 0, 1)) }}</div>
            <div class="profile__main">
                <h1 class="profile__name">{{ $name }}</h1>
                <div class="profile__email">{{ $email }}</div>
            </div>
            <div class="profile__stats">
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
        <div class="profile__retention">
            Data retention:
            @if ($retentionDeletionAt)
                personal data is auto-anonymised on <strong>{{ $retentionDeletionAt->format('j M Y') }}</strong>
            @else
                no deletion date set
            @endif
            ({{ $retentionMonths }} months from their last order, or from the event date for tickets to an upcoming event).
        </div>
    </div>

    <div class="tabs" id="customer-tabs">
        <div class="tab-list" role="tablist" aria-label="Customer sections">
            <button type="button" class="tab-btn" role="tab" id="tab-orders" aria-controls="panel-orders" data-tab="orders">Orders <span class="tab-count">{{ $orders->count() }}</span></button>
            <button type="button" class="tab-btn" role="tab" id="tab-consents" aria-controls="panel-consents" data-tab="consents">Consent <span class="tab-count">{{ $consents->count() }}</span></button>
            @if ($canManageGdpr)
                <button type="button" class="tab-btn" role="tab" id="tab-gdpr" aria-controls="panel-gdpr" data-tab="gdpr">Data &amp; GDPR</button>
            @endif
        </div>

        <div class="tab-panel" role="tabpanel" id="panel-orders" aria-labelledby="tab-orders" data-tab-panel="orders">
<div class="panel">
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Event</th>
                    <th scope="col">Placed</th>
                    <th scope="col">Status</th>
                    <th scope="col">Scanned</th>
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
                        <td>
                            @if ($order->scanned_at)
                                <span class="cell-strong" style="font-weight: 600;">{{ $order->scanned_at->format('j M Y, H:i') }}</span>
                                @if ($order->scannedBy)
                                    <span class="cell-dim">by {{ $order->scannedBy->name }}</span>
                                @endif
                            @else
                                <span class="cell-dim">Not scanned</span>
                            @endif
                        </td>
                        <td class="num">{{ $money($order->order_total_minor) }}</td>
                        <td class="num"><a class="panel__link" href="{{ route('dashboard.orders.show', $order) }}">View</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
</div>
        </div>

        <div class="tab-panel" role="tabpanel" id="panel-consents" aria-labelledby="tab-consents" data-tab-panel="consents" hidden>
<div class="panel">
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
        </div>

        @if ($canManageGdpr)
        <div class="tab-panel" role="tabpanel" id="panel-gdpr" aria-labelledby="tab-gdpr" data-tab-panel="gdpr" hidden>
<div class="panel">
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
        </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
(function () {
    var root = document.getElementById('customer-tabs');
    if (!root) return;
    var buttons = [].slice.call(root.querySelectorAll('[data-tab]'));
    var panels = [].slice.call(root.querySelectorAll('[data-tab-panel]'));
    var names = buttons.map(function (b) { return b.getAttribute('data-tab'); });
    function activate(name, focus) {
        buttons.forEach(function (b) {
            var on = b.getAttribute('data-tab') === name;
            b.setAttribute('aria-selected', String(on));
            b.tabIndex = on ? 0 : -1;
            if (on && focus) b.focus();
        });
        panels.forEach(function (p) { p.hidden = p.getAttribute('data-tab-panel') !== name; });
        if (history.replaceState) history.replaceState(null, '', '#' + name);
    }
    buttons.forEach(function (b, i) {
        b.addEventListener('click', function () { activate(b.getAttribute('data-tab')); });
        b.addEventListener('keydown', function (e) {
            var n = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : null;
            if (n === null) return;
            e.preventDefault();
            activate(names[(n + names.length) % names.length], true);
        });
    });
    var h = location.hash.replace('#', '');
    activate(names.indexOf(h) > -1 ? h : names[0]);
})();
</script>
@endpush
