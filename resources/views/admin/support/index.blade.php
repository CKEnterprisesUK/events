@extends('layouts.dashboard')

@section('title', 'Super-Admin — Support tickets')

@push('head')
<style>
    .support-filters { display: flex; gap: 0.5rem; margin: 0 0 1rem; flex-wrap: wrap; }
    .support-filters a {
        padding: 0.4rem 0.85rem; border: 1px solid var(--border); border-radius: 999px;
        text-decoration: none; color: inherit; font-size: 0.9rem;
    }
    .support-filters a.is-active { background: var(--brand); color: #fff; border-color: var(--brand); }
    .support-status { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
    .support-status.is-open { color: #b45309; }
    .support-status.is-closed { color: #047857; }
    .admin-table td .ticket-subject { font-weight: 600; }
    .admin-table td .ticket-consent { font-size: 0.78rem; color: #6b7280; }
</style>
@endpush

@section('content')
    <div class="admin-head">
        <div>
            <h1>Support tickets</h1>
            <p>Requests raised from Contact support across every company.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="support-filters" role="tablist" aria-label="Filter tickets">
        <a href="{{ route('admin.support.index', ['status' => 'open']) }}" class="{{ $filter === 'open' ? 'is-active' : '' }}">Open ({{ number_format($openCount) }})</a>
        <a href="{{ route('admin.support.index', ['status' => 'closed']) }}" class="{{ $filter === 'closed' ? 'is-active' : '' }}">Closed ({{ number_format($closedCount) }})</a>
        <a href="{{ route('admin.support.index', ['status' => 'all']) }}" class="{{ $filter === 'all' ? 'is-active' : '' }}">All ({{ number_format($totalCount) }})</a>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>{{ ucfirst($filter) }} tickets</h2></div>
        @if ($tickets->isEmpty())
            <div class="admin-empty">No tickets to show.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Status</th>
                        <th scope="col">Company</th>
                        <th scope="col">Subject</th>
                        <th scope="col">Category</th>
                        <th scope="col">Raised</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tickets as $ticket)
                        <tr data-ticket-id="{{ $ticket->id }}">
                            <td>
                                <span class="support-status {{ $ticket->isClosed() ? 'is-closed' : 'is-open' }}">{{ $ticket->statusLabel() }}</span>
                            </td>
                            <td>{{ $companyNames->get($ticket->company_id, 'Unknown company') }}</td>
                            <td>
                                <a href="{{ route('admin.support.show', $ticket->id) }}" class="ticket-subject">{{ $ticket->subject }}</a>
                                @if ($ticket->access_consent)
                                    <div class="ticket-consent">Account access granted</div>
                                @endif
                            </td>
                            <td>{{ $ticket->categoryLabel() }}</td>
                            <td>{{ optional($ticket->created_at)->format('j M Y, H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
