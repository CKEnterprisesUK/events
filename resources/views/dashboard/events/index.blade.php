@extends('layouts.dashboard')

@section('title', 'Events')

@section('page_title', 'Events')

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
        </div>

        @php
            $statusOf = function ($event) {
                if ($event->isCancelled()) return 'cancelled';
                return $event->isPublished() ? 'published' : 'draft';
            };
        @endphp

        {{-- Desktop table: clickable rows, uses the full container width. --}}
        <div class="panel only-desktop">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">Date &amp; time</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Tickets sold</th>
                        <th scope="col" class="num">Orders</th>
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
                            <td class="num">{{ isset($event->sell_through) ? number_format($sold) : '—' }}</td>
                            <td class="num">{{ isset($event->confirmed_orders_count) ? number_format($event->confirmed_orders_count) : '—' }}</td>
                            <td class="num"><a class="panel__link row-action" href="{{ route('dashboard.events.show', $event) }}">Manage</a></td>
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
                <div class="record-card" data-event-row
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
                    <a class="btn btn-outline btn-sm record-card__action" href="{{ route('dashboard.events.show', $event) }}">Manage</a>
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
</script>
@endpush
