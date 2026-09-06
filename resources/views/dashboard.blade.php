@extends('layouts.dashboard')

@section('title', 'Dashboard')

@section('page_title', 'Welcome back, ' . explode(' ', auth()->user()->name)[0])

@section('content')
    @php $company = auth()->user()->company; @endphp

    <p class="muted" style="margin-top: -0.5rem;">
        Here's a quick way into everything you can manage
        @if ($company) for <strong>{{ $company->name }}</strong>@endif.
    </p>

    <div class="card-grid" style="margin-top: 1.5rem;">
        @can('events')
            <a class="card quick-card" href="{{ route('dashboard.events.index') }}">
                <h3>&#127903; Events</h3>
                <p class="muted">Create events, add ticket types, publish and manage orders.</p>
            </a>
        @endcan

        @can('view_reports')
            <a class="card quick-card" href="{{ route('dashboard.reports.index') }}">
                <h3>&#128200; Reports &amp; Payouts</h3>
                <p class="muted">Review realised sales, fees and net payouts to your account.</p>
            </a>
        @endcan

        @can('check_in')
            <a class="card quick-card" href="{{ route('dashboard.scan.index') }}">
                <h3>&#128241; Scan tickets</h3>
                <p class="muted">Open the camera scanner to check attendees in at the door.</p>
            </a>
        @endcan

        @can('users')
            <a class="card quick-card" href="{{ route('dashboard.users.index') }}">
                <h3>&#128101; Team</h3>
                <p class="muted">Invite admins, accountants and scanners, and manage roles.</p>
            </a>
        @endcan

        @can('stripe')
            <a class="card quick-card" href="{{ route('dashboard.stripe.status') }}">
                <h3>&#128179; Payments</h3>
                <p class="muted">Connect your Stripe account so payouts settle directly to you.</p>
            </a>
        @endcan

        @can('settings')
            <a class="card quick-card" href="{{ route('dashboard.branding.edit') }}">
                <h3>&#127912; Branding</h3>
                <p class="muted">Set your logo, colours, terms and custom ticket fields.</p>
            </a>
        @endcan
    </div>

    @if ($company)
        <div class="card" style="margin-top: 1.5rem;">
            <h3 style="margin-top: 0;">Your storefront</h3>
            <p class="muted">Customers buy tickets from your public storefront:</p>
            <p>
                <a class="btn btn-outline btn-sm" href="{{ url('/' . $company->slug) }}" target="_blank" rel="noopener">
                    {{ rtrim(url('/'), '/') }}/{{ $company->slug }}
                </a>
            </p>
        </div>
    @endif
@endsection

@push('head')
<style>
    .quick-card { display: block; text-decoration: none; color: inherit; transition: box-shadow 0.15s ease, transform 0.15s ease; }
    .quick-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.08); transform: translateY(-2px); }
    .quick-card h3 { margin: 0 0 0.4rem; }
    .quick-card p { margin: 0; }
</style>
@endpush
