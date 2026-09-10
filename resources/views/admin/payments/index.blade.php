@extends('layouts.dashboard')

@section('title', 'Super-Admin — Connected accounts')

@section('content')
    <div class="admin-head">
        <div>
            <h1>Connected accounts</h1>
            <p>Stripe Connect onboarding status for every company. Use this to see who can take card payments and who is stuck part-way through onboarding.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="admin-stats">
        <div class="admin-stat">
            <span class="admin-stat__label">Companies</span>
            <span class="admin-stat__value" data-metric="all">{{ number_format($totals['all']) }}</span>
            <span class="admin-stat__sub">Total on platform</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Ready</span>
            <span class="admin-stat__value" data-metric="ready">{{ number_format($totals['ready']) }}</span>
            <span class="admin-stat__sub">Charges enabled</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Incomplete</span>
            <span class="admin-stat__value" data-metric="incomplete">{{ number_format($totals['incomplete']) }}</span>
            <span class="admin-stat__sub">Onboarding not finished</span>
        </div>
        <div class="admin-stat">
            <span class="admin-stat__label">Not started</span>
            <span class="admin-stat__value" data-metric="none">{{ number_format($totals['none']) }}</span>
            <span class="admin-stat__sub">No connected account</span>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head">
            <h2>Incomplete onboarding ({{ number_format($incomplete->count()) }})</h2>
        </div>
        <p class="muted" style="padding: 0 1rem;">
            These companies started connecting Stripe but charges are not enabled yet — often awaiting verification or an unfinished onboarding step. They cannot sell paid tickets until this clears. This is usually the answer to “I’m not getting paid”.
        </p>
        @if ($incomplete->isEmpty())
            <div class="admin-empty">No companies are stuck in onboarding.</div>
        @else
            @include('admin.payments.partials.table', ['companies' => $incomplete])
        @endif
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head">
            <h2>Not started ({{ number_format($none->count()) }})</h2>
        </div>
        @if ($none->isEmpty())
            <div class="admin-empty">Every company has begun Stripe onboarding.</div>
        @else
            @include('admin.payments.partials.table', ['companies' => $none])
        @endif
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head">
            <h2>Ready ({{ number_format($ready->count()) }})</h2>
        </div>
        @if ($ready->isEmpty())
            <div class="admin-empty">No companies can take payments yet.</div>
        @else
            @include('admin.payments.partials.table', ['companies' => $ready])
        @endif
    </div>
@endsection
