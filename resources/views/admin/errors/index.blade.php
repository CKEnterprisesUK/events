@extends('layouts.dashboard')

@section('title', 'Super-Admin — Error reports')

@push('head')
<style>
    .err-filter { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin: 1rem 0 1.25rem; }
    .err-filter .field { margin: 0; }
    .err-filter input, .err-filter select { padding: 0.5rem 0.7rem; border: 1px solid var(--border); border-radius: 0.5rem; }
    .err-filter label { display: block; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 0.25rem; }
    .err-filter .check { display: flex; align-items: center; gap: 0.4rem; }
    .err-ref { font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; font-weight: 700; }
    .err-status { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
    .err-status.is-open { color: #b45309; }
    .err-status.is-resolved { color: #047857; }
    .err-exc { color: var(--muted); font-size: 0.82rem; }
    .err-msg { display: block; max-width: 42ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pager { display: flex; justify-content: center; padding: 1rem; }
</style>
@endpush

@section('content')
    <div class="admin-head">
        <div>
            <h1>Error reports</h1>
            <p>Uncaught server errors captured across every company. Paste a customer's reference (e.g. <code>ERR-3F9A2B7C</code>) to find the full trace. {{ number_format($unresolvedCount) }} unresolved.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <form method="GET" action="{{ route('admin.errors.index') }}" class="err-filter">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" name="q" id="q" value="{{ $search }}" placeholder="Reference, URL or message">
        </div>
        <div class="field">
            <label for="company_id">Company</label>
            <select name="company_id" id="company_id">
                <option value="0">All companies</option>
                @foreach ($companies as $id => $name)
                    <option value="{{ $id }}" @selected($companyId === (int) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field check">
            <input type="checkbox" name="unresolved" id="unresolved" value="1" @checked($unresolvedOnly)>
            <label for="unresolved" style="margin:0;text-transform:none;letter-spacing:0;">Unresolved only</label>
        </div>
        <button type="submit" class="btn">Filter</button>
        <a class="btn btn-outline" href="{{ route('admin.errors.index') }}">Clear</a>
    </form>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Captured errors</h2></div>
        @if ($reports->isEmpty())
            <div class="admin-empty">No errors for the selected filters.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Reference</th>
                        <th scope="col">Error</th>
                        <th scope="col">Company</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reports as $report)
                        <tr>
                            <td title="{{ optional($report->created_at)->format('j M Y, H:i:s') }}">{{ optional($report->created_at)->format('j M Y, H:i') }}</td>
                            <td>
                                <a href="{{ route('admin.errors.show', $report->id) }}" class="err-ref">{{ $report->reference }}</a>
                            </td>
                            <td>
                                <span class="err-exc">{{ $report->shortExceptionClass() }}</span>
                                <span class="err-msg" title="{{ $report->message }}">{{ $report->message ?: '—' }}</span>
                            </td>
                            <td>{{ $report->company?->name ?? '—' }}</td>
                            <td>
                                <span class="err-status {{ $report->isResolved() ? 'is-resolved' : 'is-open' }}">
                                    {{ $report->isResolved() ? 'Resolved' : 'Open' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="pager">{{ $reports->links() }}</div>
        @endif
    </div>
@endsection
