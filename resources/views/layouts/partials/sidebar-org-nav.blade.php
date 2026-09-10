{{--
    Organisation / platform primary navigation.

    The default contents of the main dark sidebar (#appSidebar). Rendered for
    the platform (Super_Admin) surface, the company dashboard, and an
    impersonating Super_Admin. This is the "organisation context" navigation.

    When the request is inside a single event, layouts/event.blade.php sets
    $eventNav and the sidebar renders layouts.partials.sidebar-event-nav
    INSTEAD of this partial — the app never shows two navigation systems at once.

    Expects (all supplied by layouts/dashboard.blade.php):
      $isSuperAdmin, $impersonating, $company, $navActive
--}}
@if ($isSuperAdmin && ! $impersonating)
    {{-- Super Admin platform surface --}}
    <p class="nav-section">Platform</p>
    <a class="nav-link {{ $navActive('admin.home') ? 'active' : '' }}" @if($navActive('admin.home')) aria-current="page" @endif href="{{ route('admin.home') }}"><x-icon name="dashboard" class="nav-ico" /> Dashboard</a>
    <a class="nav-link {{ $navActive('admin.clients.*') ? 'active' : '' }}" @if($navActive('admin.clients.*')) aria-current="page" @endif href="{{ route('admin.clients.index') }}"><x-icon name="customers" class="nav-ico" /> Clients</a>
    <a class="nav-link {{ $navActive('admin.transactions.*') ? 'active' : '' }}" @if($navActive('admin.transactions.*')) aria-current="page" @endif href="{{ route('admin.transactions.index') }}"><x-icon name="payments" class="nav-ico" /> Transactions</a>
    <a class="nav-link {{ $navActive('admin.audit.*') ? 'active' : '' }}" @if($navActive('admin.audit.*')) aria-current="page" @endif href="{{ route('admin.audit.index') }}"><x-icon name="history" class="nav-ico" /> Audit trail</a>
    <a class="nav-link {{ $navActive('admin.fees.*') ? 'active' : '' }}" @if($navActive('admin.fees.*')) aria-current="page" @endif href="{{ route('admin.fees.index') }}"><x-icon name="payments" class="nav-ico" /> Fees</a>
    <a class="nav-link {{ $navActive('admin.legal.*') ? 'active' : '' }}" @if($navActive('admin.legal.*')) aria-current="page" @endif href="{{ route('admin.legal.index') }}"><x-icon name="report" class="nav-ico" /> Trust &amp; Legal</a>
    <a class="nav-link {{ $navActive('admin.reserved-slugs.*') ? 'active' : '' }}" @if($navActive('admin.reserved-slugs.*')) aria-current="page" @endif href="{{ route('admin.reserved-slugs.index') }}"><x-icon name="cross" class="nav-ico" /> Reserved slugs</a>
    <a class="nav-link {{ $navActive('admin.settings.*') ? 'active' : '' }}" @if($navActive('admin.settings.*')) aria-current="page" @endif href="{{ route('admin.settings.index') }}"><x-icon name="settings" class="nav-ico" /> Settings</a>
@else
    {{-- Company dashboard, adapts to the user's role --}}
    @if ($impersonating)
        <p class="nav-section">Super Admin</p>
        <a class="nav-link" href="{{ route('admin.home') }}"><x-icon name="chevron" class="nav-ico" /> Back to platform</a>
    @endif
    <p class="nav-section">Overview</p>
    <a class="nav-link {{ $navActive('dashboard.home') ? 'active' : '' }}" @if($navActive('dashboard.home')) aria-current="page" @endif href="{{ route('dashboard.home') }}"><x-icon name="dashboard" class="nav-ico" /> Dashboard</a>

    @can('events')
        <p class="nav-section">Manage</p>
        <a class="nav-link {{ $navActive('dashboard.events.*') ? 'active' : '' }}" @if($navActive('dashboard.events.*')) aria-current="page" @endif href="{{ route('dashboard.events.index') }}"><x-icon name="events" class="nav-ico" /> Events</a>
        <a class="nav-link {{ $navActive('dashboard.sharing.*') ? 'active' : '' }}" @if($navActive('dashboard.sharing.*')) aria-current="page" @endif href="{{ route('dashboard.sharing.index') }}"><x-icon name="share" class="nav-ico" /> Sharing</a>
    @endcan

    @can('orders')
        <a class="nav-link {{ $navActive('dashboard.orders.*') ? 'active' : '' }}" @if($navActive('dashboard.orders.*')) aria-current="page" @endif href="{{ route('dashboard.orders.index') }}"><x-icon name="orders" class="nav-ico" /> Orders</a>
        <a class="nav-link {{ $navActive('dashboard.customers.*') ? 'active' : '' }}" @if($navActive('dashboard.customers.*')) aria-current="page" @endif href="{{ route('dashboard.customers.index') }}"><x-icon name="customers" class="nav-ico" /> Customers</a>
    @endcan

    @can('view_reports')
        <p class="nav-section">Finance</p>
        <a class="nav-link {{ $navActive('dashboard.reports.*') ? 'active' : '' }}" @if($navActive('dashboard.reports.*')) aria-current="page" @endif href="{{ route('dashboard.reports.index') }}"><x-icon name="reports" class="nav-ico" /> Reports &amp; Payouts</a>
    @endcan

    @can('check_in')
        <p class="nav-section">Door</p>
        <a class="nav-link {{ $navActive('dashboard.scan.*') ? 'active' : '' }}" @if($navActive('dashboard.scan.*')) aria-current="page" @endif href="{{ route('dashboard.scan.index') }}"><x-icon name="scan" class="nav-ico" /> Scan tickets</a>
    @endcan

    @canany(['settings', 'stripe', 'users'])
        <p class="nav-section">Company</p>
        @can('users')
            <a class="nav-link {{ $navActive('dashboard.users.*') ? 'active' : '' }}" @if($navActive('dashboard.users.*')) aria-current="page" @endif href="{{ route('dashboard.users.index') }}"><x-icon name="team" class="nav-ico" /> Team</a>
        @endcan
        @can('settings')
            {{-- Only light up for COMPANY-level settings/branding.
                 Per-event branding (dashboard.branding.event.*) is reached from
                 an Event, so it must not mark Settings active. --}}
            <a class="nav-link {{ $navActive('dashboard.settings.*', 'dashboard.branding.edit', 'dashboard.branding.update') ? 'active' : '' }}" @if($navActive('dashboard.settings.*', 'dashboard.branding.edit', 'dashboard.branding.update')) aria-current="page" @endif href="{{ route('dashboard.branding.edit') }}"><x-icon name="settings" class="nav-ico" /> Settings</a>
        @endcan
        @can('stripe')
            <a class="nav-link {{ $navActive('dashboard.stripe.*') ? 'active' : '' }}" @if($navActive('dashboard.stripe.*')) aria-current="page" @endif href="{{ route('dashboard.stripe.status') }}"><x-icon name="payments" class="nav-ico" /> Payments</a>
        @endcan
    @endcanany

    @can('view_audit_log')
        <p class="nav-section">Oversight</p>
        <a class="nav-link {{ $navActive('dashboard.activity.*') ? 'active' : '' }}" @if($navActive('dashboard.activity.*')) aria-current="page" @endif href="{{ route('dashboard.activity.index') }}"><x-icon name="activity" class="nav-ico" /> Activity</a>
    @endcan

    @if ($company)
        <p class="nav-section">Public</p>
        <a class="nav-link" href="{{ url('/' . $company->slug) }}" target="_blank" rel="noopener"><x-icon name="storefront" class="nav-ico" /> View storefront</a>
    @endif
@endif

{{-- Help & support. Rendered for BOTH the Super Admin platform surface and
     every Company dashboard, so it is always available. Pinned to the bottom of
     the sidebar via `nav-section-help` (margin-top:auto). --}}
<p class="nav-section nav-section-help">Support</p>
<a class="nav-link {{ $navActive('dashboard.help.*') ? 'active' : '' }}" @if($navActive('dashboard.help.*')) aria-current="page" @endif href="{{ route('dashboard.help.index') }}"><x-icon name="help" class="nav-ico" /> Help</a>
@if ($isSuperAdmin && ! $impersonating)
    <a class="nav-link {{ $navActive('admin.support.*') ? 'active' : '' }}" @if($navActive('admin.support.*')) aria-current="page" @endif href="{{ route('admin.support.index') }}"><x-icon name="support" class="nav-ico" /> Support tickets</a>
@else
    <a class="nav-link {{ $navActive('dashboard.support.*') ? 'active' : '' }}" @if($navActive('dashboard.support.*')) aria-current="page" @endif href="{{ route('dashboard.support.create') }}"><x-icon name="support" class="nav-ico" /> Contact support</a>
@endif
