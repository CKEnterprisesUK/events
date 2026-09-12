@extends('layouts.dashboard')

@section('title', 'Super-Admin — Error ' . $report->reference)

@push('head')
<style>
    .err-grid { display: grid; gap: 1.5rem; max-width: 960px; }
    .err-meta { list-style: none; margin: 0; padding: 0; }
    .err-meta li { display: flex; justify-content: space-between; gap: 1rem; padding: 0.55rem 0; border-bottom: 1px solid var(--border); }
    .err-meta li:last-child { border-bottom: 0; }
    .err-meta .k { color: #6b7280; }
    .err-meta .v { font-weight: 600; text-align: right; word-break: break-word; }
    .err-meta .v.mono { font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; font-weight: 500; }
    .err-status { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
    .err-status.is-open { color: #b45309; }
    .err-status.is-resolved { color: #047857; }
    .err-message { white-space: pre-wrap; line-height: 1.6; margin: 0; padding: 1rem 1.25rem; }
    .err-trace { margin: 0; padding: 1rem 1.25rem; overflow-x: auto; font-family: ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace; font-size: 0.8rem; line-height: 1.5; white-space: pre; background: #0f1425; color: #c1c5d4; border-radius: 0 0 0.6rem 0.6rem; }
    .err-actions { display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap; padding: 1rem 1.25rem; }
    .err-context { list-style: none; margin: 0; padding: 1rem 1.25rem; }
    .err-context li { padding: 0.35rem 0; }
    .err-context code { background: #f3f4f6; padding: 0.05rem 0.35rem; border-radius: 0.3rem; }
</style>
@endpush

@section('content')
    <div class="admin-head">
        <div>
            <h1 style="font-family: ui-monospace, monospace;">{{ $report->reference }}</h1>
            <p><a href="{{ route('admin.errors.index') }}">&larr; Back to error reports</a></p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <div class="err-grid">
        <div class="panel">
            <div class="panel__head"><h2>Overview</h2></div>
            <div style="padding: 0 1.25rem;">
                <ul class="err-meta">
                    <li>
                        <span class="k">Status</span>
                        <span class="v"><span class="err-status {{ $report->isResolved() ? 'is-resolved' : 'is-open' }}">{{ $report->isResolved() ? 'Resolved' : 'Open' }}</span></span>
                    </li>
                    <li>
                        <span class="k">Exception</span>
                        <span class="v mono">{{ $report->exception_class ?? 'Unknown' }}</span>
                    </li>
                    <li>
                        <span class="k">Where</span>
                        <span class="v mono">{{ $report->file }}@if($report->line):{{ $report->line }}@endif</span>
                    </li>
                    <li>
                        <span class="k">HTTP status</span>
                        <span class="v">{{ $report->status_code }}</span>
                    </li>
                    <li>
                        <span class="k">When</span>
                        <span class="v">{{ optional($report->created_at)->format('j M Y, H:i:s') }}</span>
                    </li>
                    <li>
                        <span class="k">Company</span>
                        <span class="v">
                            @if ($report->company)
                                <a href="{{ route('admin.clients.show', $report->company) }}">{{ $report->company->name }}</a>
                            @else
                                — (platform / system)
                            @endif
                        </span>
                    </li>
                    <li>
                        <span class="k">User</span>
                        <span class="v">
                            {{ $report->user?->name ?? 'Guest / system' }}
                            @if ($report->user?->email)
                                &lt;{{ $report->user->email }}&gt;
                            @endif
                        </span>
                    </li>
                    @if ($report->resolved_at)
                        <li>
                            <span class="k">Resolved</span>
                            <span class="v">{{ $report->resolved_at->format('j M Y, H:i') }}</span>
                        </li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="panel">
            <div class="panel__head"><h2>Message</h2></div>
            <p class="err-message">{{ $report->message ?: '(no message)' }}</p>
        </div>

        <div class="panel">
            <div class="panel__head"><h2>Request</h2></div>
            <div style="padding: 0 1.25rem;">
                <ul class="err-meta">
                    <li>
                        <span class="k">Method &amp; URL</span>
                        <span class="v mono">{{ $report->method }} {{ $report->url ?? '—' }}</span>
                    </li>
                    <li>
                        <span class="k">IP address</span>
                        <span class="v mono">{{ $report->ip_address ?? '—' }}</span>
                    </li>
                    <li>
                        <span class="k">User agent</span>
                        <span class="v mono">{{ $report->user_agent ?? '—' }}</span>
                    </li>
                </ul>
            </div>
            @if (! empty($report->context))
                <ul class="err-context">
                    @foreach ($report->context as $key => $value)
                        <li>{{ str_replace('_', ' ', $key) }}: <code>{{ is_scalar($value) ? ($value === '' ? '—' : $value) : json_encode($value) }}</code></li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="panel">
            <div class="panel__head"><h2>Stack trace</h2></div>
            <pre class="err-trace">{{ $report->trace ?: '(no trace captured)' }}</pre>
        </div>

        <div class="panel">
            <div class="panel__head"><h2>Manage</h2></div>
            <div class="err-actions">
                @if ($report->isResolved())
                    <form method="POST" action="{{ route('admin.errors.status', $report->id) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="action" value="reopen">
                        <button type="submit" class="btn btn-outline">Reopen</button>
                    </form>
                    <span class="muted">This report is marked resolved.</span>
                @else
                    <form method="POST" action="{{ route('admin.errors.status', $report->id) }}">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="action" value="resolve">
                        <button type="submit" class="btn">Mark resolved</button>
                    </form>
                    <span class="muted">Mark this handled once you've dealt with it.</span>
                @endif
            </div>
        </div>
    </div>
@endsection
