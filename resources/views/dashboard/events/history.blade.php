{{--
    History screen: this Event's own audit trail (created / updated / published
    / check-ins reset), most recent first, plus the "reset check-ins" control.

    Viewing is gated on ACTION_MANAGE_EVENTS (like every manage screen); the
    reset control is shown only to roles holding ACTION_RESET_SCANS (Owner /
    Admin) and POSTs to dashboard.events.reset-scans.

    Expects (from EventController::history()):
      $event        — the Event being managed.
      $logs         — paginator of this Event's App\Models\AuditLog rows.
      $scannedCount — confirmed orders currently checked in (the reset clears).
--}}
@extends('layouts.event')

@section('active_section', 'history')

@push('head')
<style>
    .audit-cat { display: inline-block; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em; color: var(--muted); }
    .audit-when { white-space: nowrap; }
    .audit-context { margin: 0.35rem 0 0; padding: 0; list-style: none; font-size: 0.82rem; color: var(--muted); }
    .audit-context li { display: inline-block; margin-right: 0.9rem; }
    .audit-context code { background: var(--surface-2, #f3f4f6); padding: 0.05rem 0.3rem; border-radius: 0.3rem; }
    .badge-staff { display: inline-block; font-size: 0.72rem; font-weight: 600; padding: 0.1rem 0.45rem; border-radius: 0.4rem; background: #fde68a; color: #713f12; margin-left: 0.4rem; }
    .pager { display: flex; justify-content: center; padding: 1rem; }
</style>
@endpush

@section('section')
    {{-- Reset check-ins (Owner / Admin only) ------------------------------ --}}
    @can('reset_scans')
        <div class="panel">
            <div class="panel__head"><h2>Reset check-ins</h2></div>
            <div class="panel__body">
                <p class="muted">
                    Clears every check-in for this event so the door can scan
                    tickets again from a clean slate. Tickets and orders are not
                    affected — only their scanned status is cleared. This is
                    recorded in the history below.
                </p>
                @if ($scannedCount === 0)
                    <p class="muted"><em>No tickets are currently checked in.</em></p>
                @else
                    <form method="POST" action="{{ route('dashboard.events.reset-scans', $event) }}" class="inline-form"
                          onsubmit="return confirm('Reset all check-ins for this event? {{ $scannedCount }} scanned ticket(s) will be marked un-scanned. This cannot be undone.');">
                        @csrf
                        <button type="submit" class="btn btn-danger">
                            Reset {{ $scannedCount }} check-in{{ $scannedCount === 1 ? '' : 's' }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    @endcan

    {{-- Event activity trail --------------------------------------------- --}}
    <div class="panel">
        <div class="panel__head"><h2>Event history</h2></div>
        @if ($logs->isEmpty())
            <div class="empty"><p>No activity recorded for this event yet.</p></div>
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
