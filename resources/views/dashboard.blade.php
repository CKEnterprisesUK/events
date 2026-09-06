@extends('layouts.dashboard')

@section('title', 'Dashboard')

@section('content')
    @php
        $user = auth()->user();
        $company = $user->company;
        $firstName = explode(' ', trim($user->name))[0] ?? $user->name;
        $money = function (int $minor) use ($currency) {
            $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
            $symbol = $symbols[$currency] ?? '';
            return $symbol . number_format($minor / 100, 2);
        };
    @endphp

    <div class="dash-head">
        <div>
            <p class="dash-eyebrow">{{ now()->format('l, j F Y') }}</p>
            <h1>Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}, {{ $firstName }}</h1>
            @if ($company)
                <p class="muted">{{ $company->name }}</p>
            @endif
        </div>
        @can('events')
            <div class="dash-head__actions">
                <a class="btn" href="{{ route('dashboard.events.index') }}">Manage events</a>
            </div>
        @endcan
    </div>

    @if ($stats)
        <div class="stat-row">
            <div class="stat">
                <span class="stat__label">Events</span>
                <span class="stat__value">{{ $stats['total_events'] }}</span>
                <span class="stat__sub">{{ $stats['published_events'] }} published</span>
            </div>
            <div class="stat">
                <span class="stat__label">Confirmed orders</span>
                <span class="stat__value">{{ number_format($stats['confirmed_orders']) }}</span>
                <span class="stat__sub">Paid &amp; free-confirmed</span>
            </div>
            <div class="stat">
                <span class="stat__label">Gross sales</span>
                <span class="stat__value">{{ $money($stats['gross_sales_minor']) }}</span>
                <span class="stat__sub">Ticket subtotal</span>
            </div>
            <div class="stat">
                <span class="stat__label">Currency</span>
                <span class="stat__value">{{ $currency }}</span>
                <span class="stat__sub">Company default</span>
            </div>
        </div>
    @endif

    @can('events')
        <div class="panel">
            <div class="panel__head">
                <h2>Recent events</h2>
                <a class="panel__link" href="{{ route('dashboard.events.index') }}">View all</a>
            </div>

            @if ($recentEvents->isEmpty())
                <div class="empty">
                    <p>You haven't created any events yet.</p>
                    <a class="btn btn-sm" href="{{ route('dashboard.events.index') }}">Create your first event</a>
                </div>
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Event</th>
                            <th scope="col">When</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="num">Orders</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentEvents as $event)
                            <tr>
                                <td>
                                    <a class="cell-strong" href="{{ route('dashboard.events.show', $event) }}">{{ $event->name }}</a>
                                    @if ($event->venue)
                                        <span class="cell-dim">{{ $event->venue }}</span>
                                    @endif
                                </td>
                                <td>{{ $event->starts_at ? $event->starts_at->format('j M Y, H:i') : '—' }}</td>
                                <td>
                                    <span class="pill {{ $event->isPublished() ? 'pill--live' : 'pill--draft' }}">
                                        {{ $event->isPublished() ? 'Published' : 'Draft' }}
                                    </span>
                                </td>
                                <td class="num">{{ number_format($event->confirmed_orders_count) }}</td>
                                <td class="num">
                                    <a class="panel__link" href="{{ route('dashboard.events.show', $event) }}">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endcan

    {{-- Role-specific entry points for users who don't manage events. --}}
    @cannot('events')
        <div class="panel">
            <div class="panel__head"><h2>Your tools</h2></div>
            <table class="data-table">
                <tbody>
                    @can('view_reports')
                        <tr>
                            <td><span class="cell-strong">Reports &amp; payouts</span><span class="cell-dim">Realised sales, fees and net payouts</span></td>
                            <td class="num"><a class="panel__link" href="{{ route('dashboard.reports.index') }}">Open</a></td>
                        </tr>
                    @endcan
                    @can('check_in')
                        <tr>
                            <td><span class="cell-strong">Scan tickets</span><span class="cell-dim">Check attendees in at the door</span></td>
                            <td class="num"><a class="panel__link" href="{{ route('dashboard.scan.index') }}">Open</a></td>
                        </tr>
                    @endcan
                </tbody>
            </table>
        </div>
    @endcannot

    @if ($company)
        <div class="panel panel--storefront">
            <div>
                <h2>Your storefront</h2>
                <p class="muted">Customers buy tickets from your public page.</p>
            </div>
            <a class="storefront-url" href="{{ url('/' . $company->slug) }}" target="_blank" rel="noopener">
                {{ rtrim(preg_replace('#^https?://#', '', url('/')), '/') }}/{{ $company->slug }}
            </a>
        </div>
    @endif
@endsection

@push('head')
<style>
    .dash-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .dash-head h1 { margin: 0.15rem 0 0.25rem; }
    .dash-head .muted { margin: 0; }
    .dash-eyebrow { text-transform: uppercase; letter-spacing: 0.06em; font-size: 0.72rem; color: var(--muted); margin: 0; }

    .stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.75rem; }
    .stat {
        background: var(--surface); border: 1px solid var(--border); border-radius: 0.75rem;
        padding: 1.1rem 1.25rem; display: flex; flex-direction: column; gap: 0.15rem;
        border-top: 3px solid var(--brand);
    }
    .stat__label { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); }
    .stat__value { font-size: 1.75rem; font-weight: 700; color: var(--ink); line-height: 1.1; }
    .stat__sub { font-size: 0.8rem; color: var(--muted); }

    .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 0.75rem; overflow: hidden; margin-bottom: 1.5rem; }
    .panel__head { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }
    .panel__head h2 { margin: 0; font-size: 1.05rem; }
    .panel__link { font-size: 0.9rem; font-weight: 600; text-decoration: none; }

    .data-table { width: 100%; border-collapse: collapse; background: transparent; border-radius: 0; }
    .data-table th, .data-table td { padding: 0.8rem 1.25rem; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .data-table thead th { background: transparent; }
    .data-table tbody tr:last-child td { border-bottom: none; }
    .data-table .num { text-align: right; }
    .cell-strong { display: block; font-weight: 600; color: var(--ink); text-decoration: none; }
    .cell-strong:hover { color: var(--brand); }
    .cell-dim { display: block; font-size: 0.82rem; color: var(--muted); }

    .pill { display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
    .pill--live { background: #ecfdf5; color: #047857; }
    .pill--draft { background: #f3f4f6; color: #6b7280; }

    .empty { padding: 2rem 1.25rem; text-align: center; color: var(--muted); }
    .empty p { margin: 0 0 0.75rem; }

    .panel--storefront { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: 1.25rem; }
    .panel--storefront h2 { margin: 0 0 0.15rem; font-size: 1.05rem; }
    .panel--storefront .muted { margin: 0; }
    .storefront-url {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9rem;
        background: #f3f4f6; border: 1px solid var(--border); border-radius: 0.5rem;
        padding: 0.5rem 0.85rem; text-decoration: none; color: var(--ink);
    }
    .storefront-url:hover { border-color: var(--brand); color: var(--brand); }
</style>
@endpush
