@extends('layouts.dashboard')

@section('title', 'Activity')

@push('head')
<style>
    .filter-bar { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1.25rem; }
    .filter-bar .field { margin: 0; }
    .filter-bar input, .filter-bar select { padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 0.5rem; }
    .filter-bar input[type=search] { min-width: 220px; }
    .filter-bar label { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 0.25rem; }
    .pager { display: flex; justify-content: center; padding: 1rem; }
    .badge-staff { display: inline-block; font-size: 0.72rem; font-weight: 600; padding: 0.1rem 0.45rem; border-radius: 0.4rem; background: #fde68a; color: #713f12; margin-left: 0.4rem; }
    .audit-cat { display: inline-block; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em; color: var(--muted); }
    .audit-when { white-space: nowrap; }
    .audit-context { margin: 0.35rem 0 0; padding: 0; list-style: none; font-size: 0.82rem; color: var(--muted); }
    .audit-context li { display: inline-block; margin-right: 0.9rem; }
    .audit-context code { background: var(--surface-2, #f3f4f6); padding: 0.05rem 0.3rem; border-radius: 0.3rem; }
</style>
@endpush

@section('content')
    <div class="page-head">
        <h1>Activity</h1>
    </div>

    <p class="muted">
        A record of important actions taken on your account — orders cancelled or
        refunded, complimentary tickets issued, events published, team and settings
        changes, and data requests. Actions performed by CK support staff on your
        behalf are flagged.
    </p>

    <form method="GET" action="{{ route('dashboard.activity.index') }}" class="filter-bar">
        <div class="field">
            <label for="category">Category</label>
            <select name="category" id="category">
                <option value="">All</option>
                @foreach ($categoryOptions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['category'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="action">Action</label>
            <select name="action" id="action">
                <option value="">Any</option>
                @foreach ($actionOptions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['action'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="from">From</label>
            <input type="date" name="from" id="from" value="{{ $filters['from'] }}">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input type="date" name="to" id="to" value="{{ $filters['to'] }}">
        </div>
        <div class="field">
            <label for="q">Search</label>
            <input type="search" name="q" id="q" value="{{ $filters['q'] }}" placeholder="Detail or person">
        </div>
        <button type="submit" class="btn">Filter</button>
        <a class="btn btn-outline" href="{{ route('dashboard.activity.index') }}">Clear</a>
    </form>

    <div class="panel">
        @if ($logs->isEmpty())
            <div class="empty"><p>No activity recorded for the selected filters.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Who</th>
                        <th scope="col">Action</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td class="audit-when" title="{{ optional($log->created_at)->format('j M Y, H:i:s') }}">
                                {{ optional($log->created_at)->diffForHumans() }}
                            </td>
                            <td>
                                <span class="cell-strong">{{ $log->actor_label ?? 'System' }}</span>
                                @if ($log->is_impersonated)
                                    <span class="badge-staff" title="Performed by CK support staff acting on your account">CK staff</span>
                                @endif
                            </td>
                            <td>
                                <span class="audit-cat">{{ \App\Models\AuditLog::CATEGORY_LABELS[$log->category()] ?? '' }}</span><br>
                                {{ $log->actionLabel() }}
                            </td>
                            <td>
                                {{ $log->summary }}
                                @if (! empty($log->context))
                                    <ul class="audit-context">
                                        @foreach ($log->context as $key => $value)
                                            <li>{{ str_replace('_', ' ', $key) }}: <code>{{ is_scalar($value) ? $value : json_encode($value) }}</code></li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="pager">{{ $logs->links() }}</div>
        @endif
    </div>
@endsection
