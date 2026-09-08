@extends('layouts.dashboard')

@section('title', 'Super-Admin — Support ticket')

@push('head')
<style>
    .ticket-grid { display: grid; gap: 1.5rem; max-width: 760px; }
    .ticket-meta { list-style: none; margin: 0; padding: 0; }
    .ticket-meta li { display: flex; justify-content: space-between; gap: 1rem; padding: 0.55rem 0; border-bottom: 1px solid var(--border); }
    .ticket-meta li:last-child { border-bottom: 0; }
    .ticket-meta .k { color: #6b7280; }
    .ticket-meta .v { font-weight: 600; text-align: right; }
    .support-status { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
    .support-status.is-open { color: #b45309; }
    .support-status.is-closed { color: #047857; }
    .ticket-message { white-space: pre-wrap; line-height: 1.6; margin: 0; padding: 1rem 1.25rem; }
    .consent-note { padding: 0.9rem 1rem; border-radius: 0.6rem; border: 1px solid var(--border); }
    .consent-note.granted { background: rgba(4,120,87,0.06); border-color: rgba(4,120,87,0.3); }
    .ticket-actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; padding: 1rem 1.25rem; }
</style>
@endpush

@section('content')
    <div class="admin-head">
        <div>
            <h1>{{ $ticket->subject }}</h1>
            <p><a href="{{ route('admin.support.index') }}">&larr; Back to support tickets</a></p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="ticket-grid">
        <div class="panel">
            <div class="panel__head"><h2>Ticket</h2></div>
            <div style="padding: 0 1.25rem;">
                <ul class="ticket-meta">
                    <li>
                        <span class="k">Status</span>
                        <span class="v"><span class="support-status {{ $ticket->isClosed() ? 'is-closed' : 'is-open' }}">{{ $ticket->statusLabel() }}</span></span>
                    </li>
                    <li>
                        <span class="k">Company</span>
                        <span class="v">
                            @if ($company)
                                <a href="{{ route('admin.clients.show', $company) }}">{{ $company->name }}</a>
                            @else
                                Unknown company
                            @endif
                        </span>
                    </li>
                    <li>
                        <span class="k">Raised by</span>
                        <span class="v">
                            {{ $ticket->user?->name ?? 'Unknown' }}
                            @if ($ticket->user?->email)
                                &lt;{{ $ticket->user->email }}&gt;
                            @endif
                        </span>
                    </li>
                    <li>
                        <span class="k">Category</span>
                        <span class="v">{{ $ticket->categoryLabel() }}</span>
                    </li>
                    <li>
                        <span class="k">Raised</span>
                        <span class="v">{{ optional($ticket->created_at)->format('j M Y, H:i') }}</span>
                    </li>
                    @if ($ticket->resolved_at)
                        <li>
                            <span class="k">Closed</span>
                            <span class="v">{{ $ticket->resolved_at->format('j M Y, H:i') }}</span>
                        </li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="panel">
            <div class="panel__head"><h2>Message</h2></div>
            <p class="ticket-message">{{ $ticket->message }}</p>
        </div>

        <div class="panel">
            <div class="panel__head"><h2>Account access</h2></div>
            <div style="padding: 0 1.25rem 1.25rem;">
                @if ($ticket->access_consent)
                    <div class="consent-note granted">
                        <strong>Access granted.</strong>
                        The customer allowed CK Enterprises to access their account to assist
                        with this request{{ $ticket->access_consent_at ? ' on ' . $ticket->access_consent_at->format('j M Y, H:i') : '' }}.
                        You can jump into
                        @if ($company)
                            <a href="{{ route('admin.clients.show', $company) }}">{{ $company->name }}</a>
                        @else
                            the company
                        @endif
                        from Clients to investigate.
                    </div>
                @else
                    <div class="consent-note">
                        <strong>No access granted.</strong>
                        The customer did not grant account access for this request. Resolve it
                        by email, or ask them to raise a new request granting access if you need
                        to look into their account.
                    </div>
                @endif
            </div>
        </div>

        <div class="panel">
            <div class="panel__head"><h2>Manage</h2></div>
            <div class="ticket-actions">
                @if ($ticket->isClosed())
                    <form method="POST" action="{{ route('admin.support.status', $ticket->id) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="action" value="reopen">
                        <button type="submit" class="btn btn-outline">Reopen ticket</button>
                    </form>
                    <span class="muted">This ticket is closed.</span>
                @else
                    <form method="POST" action="{{ route('admin.support.status', $ticket->id) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="action" value="close">
                        <button type="submit" class="btn">Close ticket</button>
                    </form>
                    <span class="muted">Mark this ticket resolved once you’ve dealt with it.</span>
                @endif
            </div>
        </div>
    </div>
@endsection
