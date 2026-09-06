<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') &middot; {{ config('app.name', 'Event Ticketing Platform') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) ?: config('app.asset_version', '1') }}">
    @stack('head')
</head>
<body>
@php
    $user = auth()->user();
    $company = $user?->company;
    // Helper: is the current route one of the given names/prefixes?
    $navActive = fn (string ...$patterns) => collect($patterns)
        ->contains(fn ($p) => request()->routeIs($p));
@endphp
<div class="app-shell" id="appShell">
    <header class="app-header">
        <button type="button" class="nav-toggle" id="navToggle" aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="false">&#9776;</button>

        <a href="{{ url('/dashboard') }}" class="brand">
            <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name', 'Event Ticketing Platform') }}">
        </a>

        <div class="header-spacer"></div>

        <div class="header-user">
            <span class="user-name">
                <strong>{{ $user?->name }}</strong>
                @if ($company)
                    <span class="muted" style="color: var(--dark-text-dim);">&middot; {{ $company->name }}</span>
                @endif
            </span>
            @if ($user?->isSuperAdmin())
                <span class="role-badge">Super Admin</span>
            @elseif ($user?->role)
                <span class="role-badge">{{ ucfirst($user->role) }}</span>
            @endif
            <form method="POST" action="{{ url('/logout') }}">
                @csrf
                <button type="submit" class="logout-btn">Log out</button>
            </form>
        </div>
    </header>

    <div class="app-body">
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <aside class="app-sidebar" id="appSidebar">
            <nav aria-label="Primary">
                @if ($user?->isSuperAdmin())
                    {{-- Super Admin surface --}}
                    <p class="nav-section">Platform</p>
                    <a class="nav-link {{ $navActive('admin.transactions.*', 'admin.home') ? 'active' : '' }}" href="{{ route('admin.transactions.index') }}"><span class="nav-ico">&#128202;</span> Transactions</a>
                    <a class="nav-link {{ $navActive('admin.companies.*') ? 'active' : '' }}" href="{{ route('admin.companies.index') }}"><span class="nav-ico">&#127970;</span> Companies</a>
                    <a class="nav-link {{ $navActive('admin.fees.*') ? 'active' : '' }}" href="{{ route('admin.fees.index') }}"><span class="nav-ico">&#128176;</span> Fees</a>
                @else
                    {{-- Company dashboard, adapts to the user's role --}}
                    <p class="nav-section">Overview</p>
                    <a class="nav-link {{ $navActive('dashboard.home') ? 'active' : '' }}" href="{{ route('dashboard.home') }}"><span class="nav-ico">&#127968;</span> Dashboard</a>

                    @can('events')
                        <p class="nav-section">Manage</p>
                        <a class="nav-link {{ $navActive('dashboard.events.*') ? 'active' : '' }}" href="{{ route('dashboard.events.index') }}"><span class="nav-ico">&#127903;</span> Events</a>
                    @endcan

                    @can('view_reports')
                        <p class="nav-section">Finance</p>
                        <a class="nav-link {{ $navActive('dashboard.reports.*') ? 'active' : '' }}" href="{{ route('dashboard.reports.index') }}"><span class="nav-ico">&#128200;</span> Reports &amp; Payouts</a>
                    @endcan

                    @can('check_in')
                        <p class="nav-section">Door</p>
                        <a class="nav-link {{ $navActive('dashboard.scan.*') ? 'active' : '' }}" href="{{ route('dashboard.scan.index') }}"><span class="nav-ico">&#128241;</span> Scan tickets</a>
                    @endcan

                    @canany(['settings', 'stripe', 'users'])
                        <p class="nav-section">Company</p>
                        @can('users')
                            <a class="nav-link {{ $navActive('dashboard.users.*') ? 'active' : '' }}" href="{{ route('dashboard.users.index') }}"><span class="nav-ico">&#128101;</span> Team</a>
                        @endcan
                        @can('settings')
                            <a class="nav-link {{ $navActive('dashboard.branding.*') ? 'active' : '' }}" href="{{ route('dashboard.branding.edit') }}"><span class="nav-ico">&#127912;</span> Branding</a>
                            <a class="nav-link {{ $navActive('dashboard.gdpr.*') ? 'active' : '' }}" href="{{ route('dashboard.gdpr.index') }}"><span class="nav-ico">&#128274;</span> Data &amp; GDPR</a>
                        @endcan
                        @can('stripe')
                            <a class="nav-link {{ $navActive('dashboard.stripe.*') ? 'active' : '' }}" href="{{ route('dashboard.stripe.status') }}"><span class="nav-ico">&#128179;</span> Payments</a>
                        @endcan
                    @endcanany

                    @if ($company)
                        <p class="nav-section">Public</p>
                        <a class="nav-link" href="{{ url('/' . $company->slug) }}" target="_blank" rel="noopener"><span class="nav-ico">&#128279;</span> View storefront</a>
                    @endif
                @endif
            </nav>
        </aside>

        <div class="app-content">
            <main class="dashboard-main">
                @hasSection('page_title')
                    <div class="page-head">
                        <h1>@yield('page_title')</h1>
                        @yield('page_actions')
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
</div>

<script>
    (function () {
        var shell = document.getElementById('appShell');
        var toggle = document.getElementById('navToggle');
        var backdrop = document.getElementById('sidebarBackdrop');
        if (!shell || !toggle) return;

        function close() {
            shell.classList.remove('nav-open');
            toggle.setAttribute('aria-expanded', 'false');
        }
        toggle.addEventListener('click', function () {
            var open = shell.classList.toggle('nav-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        if (backdrop) backdrop.addEventListener('click', close);
        // Close on navigation (mobile) and on Escape.
        document.querySelectorAll('#appSidebar a').forEach(function (a) {
            a.addEventListener('click', close);
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    })();
</script>
@stack('scripts')
</body>
</html>
