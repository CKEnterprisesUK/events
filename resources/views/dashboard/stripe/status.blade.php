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
    .fee-mode-form { padding: 1.25rem; }
    .fee-mode-help { margin: 0 0 1rem; color: var(--muted); font-size: 0.9rem; }
    .fee-option { display: flex; gap: 0.7rem; align-items: flex-start; padding: 0.75rem; border: 1px solid var(--border); border-radius: 0.6rem; margin-bottom: 0.75rem; cursor: pointer; }
    .fee-option input { margin-top: 0.2rem; }
    .fee-option strong { display: block; color: var(--ink); }
    .fee-option__desc { display: block; font-size: 0.85rem; color: var(--muted); margin-top: 0.15rem; }
    .fee-mode-form .error { color: #b91c1c; font-size: 0.85rem; margin: 0 0 0.75rem; }
    .status-banner__cta { margin-top: 0.9rem; }
    .btn-connect { font-size: 1.02rem; font-weight: 600; padding: 0.7rem 1.4rem; }
    .status-banner__cta .btn-connect-hint { display: block; margin-top: 0.5rem; font-size: 0.85rem; color: var(--muted); }
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

    @if (session('fee_status'))
        <p class="status" data-status="saved">{{ session('fee_status') }}</p>
    @endif

    @if (! $connected)
        <div class="status-banner status-banner--off" data-status="not_connected">
            <h2>Not connected</h2>
            <p>Connect a Stripe account to sell paid tickets. Until then you can only publish free events.</p>
            <div class="status-banner__cta">
                <form method="POST" action="{{ route('dashboard.stripe.start') }}">
                    @csrf
                    <button type="submit" class="btn btn-connect" data-action="connect-stripe">Connect Stripe</button>
                    <span class="btn-connect-hint">Takes a couple of minutes. You'll be redirected to Stripe to finish setup.</span>
                </form>
            </div>
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
                <div class="kv__label">Currently</div>
                <div class="kv__value">{{ $feeModeLabel }}</div>
            </div>
            <div>
                <div class="kv__label">Payouts</div>
                <div class="kv__value">Direct to your Stripe account</div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h2>Who pays the fee</h2></div>
        @if ($canManage)
            <form method="POST" action="{{ route('dashboard.stripe.fee-mode') }}" class="fee-mode-form">
                @csrf
                @method('PUT')
                <p class="fee-mode-help">
                    Choose whether the platform fee is added onto the customer's total
                    or taken out of your ticket price. Changes apply to new orders only.
                </p>
                <label class="fee-option">
                    <input type="radio" name="fee_handling_mode" value="{{ \App\Models\Company::FEE_MODE_PASS_ON }}"
                           @checked($feeMode === \App\Models\Company::FEE_MODE_PASS_ON)>
                    <span>
                        <strong>Add the fee onto the ticket</strong>
                        <span class="fee-option__desc">The customer pays the fee on top as a booking fee. You keep the full ticket price.</span>
                    </span>
                </label>
                <label class="fee-option">
                    <input type="radio" name="fee_handling_mode" value="{{ \App\Models\Company::FEE_MODE_ABSORB }}"
                           @checked($feeMode === \App\Models\Company::FEE_MODE_ABSORB)>
                    <span>
                        <strong>Absorb the fee myself</strong>
                        <span class="fee-option__desc">The customer pays only the ticket price and the fee comes out of it.</span>
                    </span>
                </label>
                @error('fee_handling_mode')<p class="error">{{ $message }}</p>@enderror
                <button type="submit" class="btn">Save fee handling</button>
            </form>
        @else
            <div class="fee-mode-form">
                <p class="fee-mode-help">
                    The platform fee is currently {{ $feeModeLabel }}. Only the account
                    Owner can change how the fee is handled.
                </p>
            </div>
        @endif
    </div>

    @if ($connected)
        <form method="POST" action="{{ route('dashboard.stripe.start') }}">
            @csrf
            <button type="submit" class="btn">
                {{ $chargesEnabled ? 'Manage on Stripe' : 'Finish Stripe onboarding' }}
            </button>
        </form>
    @endif
@endsection
