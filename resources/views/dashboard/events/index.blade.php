@extends('layouts.dashboard')

@section('title', 'Events')

{{-- This page renders its own .page-header <h1>, so it sets mobile_context
     (for the mobile top-bar label) instead of page_title to avoid a duplicate
     heading on desktop. --}}
@section('mobile_context', 'Events')

@push('head')
<style>
    /* Inline action group in the desktop table's Actions cell: the Manage link
       plus a Duplicate submit styled to read like a sibling link. */
    .row-actions { display: inline-flex; align-items: center; gap: 0.75rem; justify-content: flex-end; }
    .row-action-form { margin: 0; display: inline; }
    button.row-action {
        background: none; border: 0; padding: 0; cursor: pointer;
        font: inherit; color: inherit;
    }
    button.row-action--muted { color: var(--muted, #667085); font-weight: 600; }
    button.row-action--muted:hover { text-decoration: underline; }
    /* Mobile card actions: keep the two buttons side by side. */
    .record-card__actions { display: flex; gap: 0.5rem; align-items: center; }
    .record-card__actions form { margin: 0; }

    /* Per-event colour accent. A small, stable swatch that aids scanning
       without shouting; derived from the event id (see $colourOf). */
    .cell-event { display: flex; align-items: center; gap: 0.7rem; min-width: 0; }
    .event-colour {
        flex: none; width: 4px; align-self: stretch; min-height: 2.2rem;
        border-radius: 999px; background: var(--event-colour, var(--ck-brand, #2349e8));
    }
    /* Mobile card: colour accent down the left edge. */
    .record-card--accent { border-left: 3px solid var(--event-colour, var(--ck-brand, #2349e8)); }

    /* Headline-metric segmented toggle in the toolbar. */
    .metric-toggle { display: inline-flex; border: 1px solid #d4d8e3; border-radius: 7px; overflow: hidden; background: #fff; }
    .metric-toggle__btn {
        appearance: none; border: 0; background: transparent; cursor: pointer;
        font: inherit; font-size: 0.8125rem; font-weight: 500; color: var(--ck-text-2, #667085);
        padding: 0 0.75rem; height: 38px; line-height: 38px;
    }
    .metric-toggle__btn + .metric-toggle__btn { border-left: 1px solid #d4d8e3; }
    .metric-toggle__btn.is-active { background: color-mix(in srgb, var(--ck-brand, #2349e8) 10%, #fff); color: var(--ck-brand, #2349e8); }
    .metric-toggle__btn:hover:not(.is-active) { background: #f3f5f8; }
</style>
@endpush

@section('content')
    <div class="page-header">
        <div class="page-header__text">
            <h1>Events</h1>
            <p class="page-header__desc">Create and manage your organisation's events.</p>
        </div>
        <div class="page-header__actions">
            <a class="btn" href="{{ route('dashboard.events.create') }}">New event</a>
        </div>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif

    @if ($events->isEmpty())
        {{-- True empty state — no fake dashboard content to fill the canvas. --}}
        <div class="empty-state">
            <h2>No events yet</h2>
            <p>Create your first event to start selling tickets.</p>
            <a class="btn" href="{{ route('dashboard.events.create') }}">Create event</a>
        </div>
    @else
        {{-- Client-side search + status filter. Genuinely functional (filters
             the rows already rendered below); no server round-trip needed, and
             it degrades gracefully to the full list when JS is off. --}}
        <div class="toolbar" data-events-toolbar>
            <div class="toolbar__search">
                <label class="sr-only" for="event-search">Search events</label>
                <input type="search" id="event-search" data-events-search
                       placeholder="Search events…" autocomplete="off">
            </div>
            <div class="toolbar__control">
                <label class="sr-only" for="event-status">Filter by status</label>
                <select id="event-status" data-events-status>
                    <option value="">All statuses</option>
                    <option value="published">Published</option>
                    <option value="draft">Draft</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            {{-- Headline-metric toggle: show a single figure column (Orders or
                 Ticket sales) so the table isn't as wide. Pure client-side; the
                 full data is always in the DOM, so it degrades to showing Orders
                 when JS is off. Choice is remembered in localStorage. --}}
            <div class="toolbar__control metric-toggle" role="group" aria-label="Headline metric" data-events-metric>
                <button type="button" class="metric-toggle__btn is-active" data-metric="orders" aria-pressed="true">Orders</button>
                <button type="button" class="metric-toggle__btn" data-metric="tickets" aria-pressed="false">Ticket sales</button>
            </div>
        </div>

        @php
            $statusOf = function ($event) {
                if ($event->isCancelled()) return 'cancelled';
                return $event->isPublished() ? 'published' : 'draft';
            };
            // Deterministic colour per event so each event reads with a stable
            // accent across reloads. Derived from the id, no backend change.
            $eventPalette = ['#2349e8', '#0ea5a4', '#7c3aed', '#db2777', '#f59e0b', '#0f766e', '#4f46e5', '#b45309'];
            $colourOf = fn ($event) => $eventPalette[(int) $event->id % count($eventPalette)];
        @endphp

        {{-- Desktop table: clickable rows, uses the full container width. --}}
        <div class="panel only-desktop">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">Date &amp; time</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num metric-col metric-col--tickets" hidden>Tickets sold</th>
                        <th scope="col" class="num metric-col metric-col--orders">Orders</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody data-events-rows>
                    @foreach ($events as $event)
                        @php
                            $poster = $event->poster_path ?? $event->company->poster_path;
                            $status = $statusOf($event);
                            $sold = (int) ($event->sell_through['sold'] ?? 0);
                        @endphp
                        <tr class="row-nav" data-event-row
                            data-name="{{ \Illuminate\Support\Str::lower($event->name) }}"
                            data-status="{{ $status }}">
                            <td>
                                <div class="cell-event">
                                    <span class="event-colour" style="--event-colour: {{ $colourOf($event) }};" aria-hidden="true"></span>
                                    @if ($poster)
                                        <img class="cell-thumb"
                                             src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($poster) }}"
                                             alt="" loading="lazy">
                                    @endif
                                    <span class="cell-event__meta">
                                        <a class="cell-strong row-link" href="{{ route('dashboard.events.show', $event) }}">{{ $event->name }}</a>
                                        @if ($event->venue)
                                            <span class="cell-dim">{{ $event->venue }}</span>
                                        @endif
                                    </span>
                                </div>
                            </td>
                            <td>{!! $event->starts_at ? e($event->starts_at->format('j M Y · H:i')) : '&mdash;' !!}</td>
                            <td>
                                <span class="pill pill--{{ $status === 'published' ? 'live' : $status }}">
                                    {{ ucfirst($status) }}
                                </span>
                            </td>
                            <td class="num metric-col metric-col--tickets" hidden>{{ isset($event->sell_through) ? number_format($sold) : '—' }}</td>
                            <td class="num metric-col metric-col--orders">{{ isset($event->confirmed_orders_count) ? number_format($event->confirmed_orders_count) : '—' }}</td>
                            <td class="num">
                                <span class="row-actions">
                                    <a class="panel__link row-action" href="{{ route('dashboard.events.show', $event) }}">Manage</a>
                                    <form method="POST" action="{{ route('dashboard.events.duplicate', $event) }}" class="row-action-form">
                                        @csrf
                                        <button type="submit" class="panel__link row-action row-action--muted">Duplicate</button>
                                    </form>
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="table-empty-filter" data-events-empty hidden>No events match your search.</p>
        </div>

        {{-- Mobile: stacked event cards. --}}
        <div class="only-mobile record-list" data-events-cards>
            @foreach ($events as $event)
                @php
                    $poster = $event->poster_path ?? $event->company->poster_path;
                    $status = $statusOf($event);
                    $sold = (int) ($event->sell_through['sold'] ?? 0);
                    $orders = (int) ($event->confirmed_orders_count ?? 0);
                @endphp
                <div class="record-card record-card--accent" data-event-row
                     style="--event-colour: {{ $colourOf($event) }};"
                     data-name="{{ \Illuminate\Support\Str::lower($event->name) }}"
                     data-status="{{ $status }}">
                    @if ($poster)
                        <img class="record-card__thumb"
                             src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($poster) }}"
                             alt="" loading="lazy">
                    @endif
                    <div class="record-card__body">
                        <a class="record-card__title cell-strong" href="{{ route('dashboard.events.show', $event) }}">{{ $event->name }}</a>
                        <span class="record-card__meta">{{ $event->starts_at ? $event->starts_at->format('j M Y · H:i') : 'Date not set' }}</span>
                        <span class="record-card__meta"><span class="pill pill--{{ $status === 'published' ? 'live' : $status }}">{{ ucfirst($status) }}</span></span>
                        <span class="record-card__meta record-card__meta--strong">
                            {{ number_format($sold) }} {{ \Illuminate\Support\Str::plural('ticket', $sold) }}
                            · {{ number_format($orders) }} {{ \Illuminate\Support\Str::plural('order', $orders) }}
                        </span>
                    </div>
                    <div class="record-card__actions">
                        <a class="btn btn-outline btn-sm record-card__action" href="{{ route('dashboard.events.show', $event) }}">Manage</a>
                        <form method="POST" action="{{ route('dashboard.events.duplicate', $event) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-sm record-card__action">Duplicate</button>
                        </form>
                    </div>
                </div>
            @endforeach
            <p class="table-empty-filter" data-events-empty hidden>No events match your search.</p>
        </div>
    @endif
@endsection

@push('scripts')
<script>
    (function () {
        var search = document.querySelector('[data-events-search]');
        var status = document.querySelector('[data-events-status]');
        if (!search && !status) return;

        var rows = Array.prototype.slice.call(document.querySelectorAll('[data-event-row]'));
        var empties = Array.prototype.slice.call(document.querySelectorAll('[data-events-empty]'));

        function apply() {
            var q = (search && search.value || '').trim().toLowerCase();
            var s = (status && status.value) || '';
            // Track visible count per container so each list shows its own
            // "no matches" line correctly.
            var visibleByParent = new Map();
            rows.forEach(function (row) {
                var matchName = !q || row.getAttribute('data-name').indexOf(q) !== -1;
                var matchStatus = !s || row.getAttribute('data-status') === s;
                var show = matchName && matchStatus;
                row.hidden = !show;
                row.style.display = show ? '' : 'none';
                var parent = row.closest('.panel, .record-list');
                if (parent) {
                    visibleByParent.set(parent, (visibleByParent.get(parent) || 0) + (show ? 1 : 0));
                }
            });
            empties.forEach(function (el) {
                var parent = el.closest('.panel, .record-list');
                el.hidden = (visibleByParent.get(parent) || 0) !== 0;
            });
        }

        if (search) search.addEventListener('input', apply);
        if (status) status.addEventListener('change', apply);
    })();

    // Headline-metric toggle: show one figure column (orders | tickets) so the
    // desktop table stays narrow. Remembers the choice in localStorage.
    (function () {
        var group = document.querySelector('[data-events-metric]');
        if (!group) return;
        var STORE = 'ck.events.metric';
        var buttons = Array.prototype.slice.call(group.querySelectorAll('.metric-toggle__btn'));

        function setMetric(metric) {
            // Toggle the per-column cells (header + body) across the table.
            document.querySelectorAll('.metric-col').forEach(function (cell) {
                var isOrders = cell.classList.contains('metric-col--orders');
                var show = (metric === 'orders') ? isOrders : !isOrders;
                cell.hidden = !show;
            });
            buttons.forEach(function (b) {
                var on = b.getAttribute('data-metric') === metric;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            try { localStorage.setItem(STORE, metric); } catch (e) {}
        }

        buttons.forEach(function (b) {
            b.addEventListener('click', function () { setMetric(b.getAttribute('data-metric')); });
        });

        var saved = null;
        try { saved = localStorage.getItem(STORE); } catch (e) {}
        setMetric(saved === 'tickets' ? 'tickets' : 'orders');
    })();
</script>
@endpush
