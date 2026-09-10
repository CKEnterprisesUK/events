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
