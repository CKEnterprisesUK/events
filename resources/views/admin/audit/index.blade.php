@extends('layouts.dashboard')

@section('title', 'Super-Admin — Audit')

@push('head')
<style>
    .audit-filter { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin: 1rem 0 1.25rem; }
    .audit-filter .field { margin: 0; }
    .audit-filter input, .audit-filter select { padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 0.5rem; }
    .audit-filter label { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 0.25rem; }
    .audit-filter .check { display: flex; align-items: center; gap: 0.4rem; }
    .badge-staff { display: inline-block; font-size: 0.72rem; font-weight: 600; padding: 0.1rem 0.45rem; border-radius: 0.4rem; background: #fde68a; color: #713f12; margin-left: 0.4rem; }
    .audit-context { margin: 0.3rem 0 0; padding: 0; list-style: none; font-size: 0.8rem; color: var(--muted); }
    .audit-context li { display: inline-block; margin-right: 0.9rem; }
    .audit-context code { background: #f3f4f6; padding: 0.05rem 0.3rem; border-radius: 0.3rem; }
    .pager { display: flex; justify-content: center; padding: 1rem; }
</style>
@endpush

@section('content')
    <div class="admin-head">
        <div>
            <h1>Platform audit trail</h1>
            <p>Every recorded action across all companies. Actions taken by support staff while impersonating a company are flagged.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('admin.audit.index') }}" class="audit-filter">
        <div class="field">
            <label for="company_id">Company</label>
            <select name="company_id" id="company_id">
                <option value="0">All companies</option>
                @foreach ($companies as $id => $name)
                    <option value="{{ $id }}" @selected($companyId === (int) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
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
        <div class="field check">
            <input type="checkbox" name="impersonated" id="impersonated" value="1" @checked($impersonatedOnly)>
            <label for="impersonated" style="margin:0;text-transform:none;letter-spacing:0;">Impersonated only</label>
        </div>
        <button type="submit" class="btn">Filter</button>
        <a class="btn btn-outline" href="{{ route('admin.audit.index') }}">Clear</a>
    </form>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Activity</h2></div>
        @if ($logs->isEmpty())
            <div class="admin-empty">No activity for the selected filters.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Company</th>
                        <th scope="col">Who</th>
                        <th scope="col">Action</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td title="{{ optional($log->created_at)->format('j M Y, H:i:s') }}">{{ optional($log->created_at)->format('j M Y, H:i') }}</td>
                            <td>{{ $log->company?->name ?? '—' }}</td>
                            <td>
                                {{ $log->actor_label ?? 'System' }}
                                @if ($log->is_impersonated)
                                    <span class="badge-staff">impersonated</span>
                                @endif
                            </td>
                            <td>{{ $log->actionLabel() }}</td>
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
