@extends('layouts.dashboard')

@section('title', 'Super-Admin — System health')

@section('content')
    <div class="admin-head">
        <div>
            <h1>System health</h1>
            <p>Live status of the infrastructure the platform depends on — database, background queue, failed jobs and cache.</p>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Overall</h2></div>
        <div class="admin-panel__body">
            <p>
                @if ($report['healthy'])
                    <span class="admin-pill admin-pill--active">All systems healthy</span>
                @else
                    <span class="admin-pill admin-pill--suspended">Attention needed</span>
                @endif
            </p>
            <p class="muted">
                The queue drains ticket emails and Stripe webhook processing. A growing backlog or failed jobs usually means the queue worker/cron has stopped — see <code>DEPLOYMENT.md</code> for the cron setup.
            </p>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Scheduled jobs (cron)</h2></div>
        <table class="admin-table" id="cron-jobs">
            <thead>
                <tr>
                    <th scope="col">Job</th>
                    <th scope="col">Status</th>
                    <th scope="col">Last run</th>
                    <th scope="col">Last result</th>
                    <th scope="col">Runs / failures</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($jobs as $job)
                    <tr data-cron="{{ $job['name'] }}">
                        <td class="cell-strong">
                            {{ $job['label'] }}
                            <br><code>{{ $job['name'] }}</code>
                            <br><span class="muted">{{ $job['description'] }}</span>
                        </td>
                        <td>
                            <span class="admin-pill {{ $job['ok'] ? 'admin-pill--active' : ($job['status'] === 'never' ? '' : 'admin-pill--suspended') }}">
                                {{ ['ok' => 'Healthy', 'failed' => 'Failed', 'overdue' => 'Overdue', 'stuck' => 'Stuck', 'never' => 'Never run'][$job['status']] }}
                            </span>
                            <br><span class="muted">{{ $job['detail'] }}</span>
                        </td>
                        <td>
                            @if ($job['last_started_at'])
                                <span title="{{ $job['last_started_at']->format('j M Y, H:i:s') }}">{{ $job['last_started_at']->format('j M, H:i') }}</span>
                                @if ($job['duration_ms'] !== null)
                                    <br><span class="muted">{{ number_format($job['duration_ms']) }} ms</span>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            {{ $job['summary'] ?: '—' }}
                            @if ($job['error'])
                                <br><span style="color:#b91c1c;">{{ \Illuminate\Support\Str::limit($job['error'], 160) }}</span>
                            @endif
                        </td>
                        <td>{{ number_format($job['runs']) }} / {{ number_format($job['failures']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="admin-panel__body">
            <p class="muted">Runs that change something (or fail) are also written to the <a href="{{ route('admin.audit.index') }}">audit trail</a> as System actions. A job is overdue when it has not started for twice its usual interval.</p>
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Checks</h2></div>
        <table class="admin-table">
            <thead>
                <tr>
                    <th scope="col">Check</th>
                    <th scope="col">Status</th>
                    <th scope="col">Detail</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['checks'] as $check)
                    <tr data-check="{{ $check['key'] }}">
                        <td class="cell-strong">{{ $check['label'] }}</td>
                        <td>
                            <span class="admin-pill {{ $check['ok'] ? 'admin-pill--active' : 'admin-pill--suspended' }}">
                                {{ $check['status'] }}
                            </span>
                        </td>
                        <td>{{ $check['detail'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
