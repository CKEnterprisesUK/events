@extends('layouts.dashboard')

@section('title', 'Payments')

@push('head')
<style>
    .status-banner { border-radius: 0.75rem; padding: 1.1rem 1.25rem; margin-bottom: 1.5rem; border: 1px solid var(--border); }
    .status-banner h2 { margin: 0 0 0.25rem; font-size: 1.1rem; }
    .status-banner p { margin: 0; }
    .status-banner--ok { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
    .status-banner--warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
    .status-banner--off { background: #f9fafb; border-color: var(--border); color: var(--ink); }
    .kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; padding: 1.25rem; }
    .kv__label { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
    .kv__value { font-weight: 600; color: var(--ink); }
    .how-list { margin: 0; padding: 0 1.25rem 1.25rem 2.4rem; }
    .how-list li { margin: 0.4rem 0; color: var(--ink); }
    .how-list li span { color: var(--muted); }
</style>
@endpush

@section('content')
    @php
        $feeModeLabel = $feeMode === \App\Models\Company::FEE_MODE_PASS_ON
            ? 'Passed on to customers (added as a booking fee)'
            : 'Absorbed by you (taken from your ticket price)';
    @endphp

    <div class="page-head">
        <h1>Payments</h1>
    </div>

    @if (! $connected)
        <div class="status-banner status-banner--off" data-status="not_connected">
            <h2>Not connected</h2>
            <p>Connect a Stripe account to sell paid tickets. Until then you can only publish free events.</p>
        </div>
    @elseif ($chargesEnabled)
        <div class="status-banner status-banner--ok" data-status="charges_enabled">
            <h2>Connected — ready to take payments</h2>
            <p>Your Stripe account is connected and charges are enabled. You can publish and sell paid tickets.</p>
        </div>
    @else
        <div class="status-banner status-banner--warn" data-status="charges_disabled">
            <h2>Connected — action needed</h2>
            <p>Your Stripe account is connected but charges aren't enabled yet. Finish Stripe onboarding to start taking payments.</p>
        </div>
    @endif

    <div class="panel">
        <div class="panel__head"><h2>How payments work</h2></div>
        <ul class="how-list">
            <li>We use <strong>Stripe Connect (Standard)</strong>. You own the Stripe account and control your payout schedule and banking details directly in Stripe. <span>Events by CK Enterprises never holds your funds.</span></li>
            <li>When a customer buys a ticket, the charge is made <strong>directly on your connected account</strong>, so ticket revenue settles to you.</li>
            <li>We take a <strong>platform fee</strong> per transaction as a Stripe <em>application fee</em>. Everything after that fee is yours.</li>
            <li>Stripe's own card-processing fees apply as normal and are handled inside your Stripe account.</li>
        </ul>
    </div>

    <div class="panel">
        <div class="panel__head"><h2>Your platform fee</h2></div>
        <div class="kv">
            <div>
                <div class="kv__label">Platform fee</div>
                <div class="kv__value">{{ rtrim(rtrim($feePercent, '0'), '.') }}% per paid ticket</div>
            </div>
            <div>
                <div class="kv__label">Who pays the fee</div>
                <div class="kv__value">{{ $feeModeLabel }}</div>
            </div>
            <div>
                <div class="kv__label">Payouts</div>
                <div class="kv__value">Direct to your Stripe account</div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('dashboard.stripe.start') }}">
        @csrf
        <button type="submit" class="btn">
            {{ $connected ? ($chargesEnabled ? 'Manage on Stripe' : 'Finish Stripe onboarding') : 'Connect Stripe' }}
        </button>
    </form>
@endsection
