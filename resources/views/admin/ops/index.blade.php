@extends('layouts.dashboard')
@section('title', 'Super-Admin — Ops (pre-prod)')
@section('content')
    <div class="admin-head">
        <div>
            <h1>Pre-prod operations</h1>
            <p>Run database migrations and rebuild sample data in-process — no SSH, no terminal, no cPanel deploy needed.</p>
        </div>
    </div>

    @if ($productionBlocked)
        <div class="admin-panel">
            <div class="admin-panel__body">
                <span class="admin-pill admin-pill--suspended">Disabled in production</span>
                <p class="muted">{{ $productionBlocked }}</p>
            </div>
        </div>
    @else
        @if (session('ops_status'))
            <div class="admin-panel">
                <div class="admin-panel__head"><h2>Result</h2></div>
                <div class="admin-panel__body">
                    <pre style="white-space:pre-wrap">{{ session('ops_status') }}</pre>
                </div>
            </div>
        @endif
        @if (session('ops_error'))
            <div class="admin-panel">
                <div class="admin-panel__body">
                    <span class="admin-pill admin-pill--suspended">Error</span>
                    <pre style="white-space:pre-wrap">{{ session('ops_error') }}</pre>
                </div>
            </div>
        @endif

        <div class="admin-panel">
            <div class="admin-panel__head"><h2>Target database</h2></div>
            <div class="admin-panel__body">
                <p>
                    Connection <code>{{ $connection }}</code>, database <code>{{ $database }}</code>.
                    @if ($connection !== 'mysql' && $connection !== 'mariadb')
                        <span class="admin-pill admin-pill--suspended">Not MySQL — check .env</span>
                    @endif
                </p>
                <p class="muted">Row counts:
                    @foreach ($counts as $table => $count)
                        <code>{{ $table }}={{ $count === null ? 'no table' : $count }}</code>@if (! $loop->last), @endif
                    @endforeach
                </p>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel__head"><h2>Actions</h2></div>
            <div class="admin-panel__body">
                <p>
                    <strong>Run migrations</strong> — applies any pending migrations. Safe and non-destructive.
                </p>
                <form method="POST" action="{{ route('admin.ops.migrate') }}" style="margin-bottom:1.5rem">
                    @csrf
                    <button type="submit" class="btn btn--primary">Run migrations</button>
                </form>

                <p>
                    <strong>Rebuild sample data</strong> — seeds prod-like data and the test logins
                    (<code>super@preprod.test</code> / <code>owner@preprod.test</code>, password <code>password</code>).
                    Seeds only when empty unless you tick "wipe first".
                </p>
                <form method="POST" action="{{ route('admin.ops.reseed') }}"
                      onsubmit="return confirm('Rebuild sample data now?');">
                    @csrf
                    <label style="display:block;margin-bottom:.5rem">
                        <input type="checkbox" name="fresh" value="1">
                        Wipe the database first (migrate:fresh — destroys all data)
                    </label>
                    <button type="submit" class="btn btn--primary">Rebuild sample data</button>
                </form>

                <hr style="margin:1.5rem 0;border:none;border-top:1px solid var(--border)">

                <p>
                    <strong>Clear caches</strong> — drops the compiled route / config / view caches.
                    Run this after every <em>Update from Remote</em> so newly pulled routes and
                    config are picked up. Non-destructive (never touches data).
                </p>
                <form method="POST" action="{{ route('admin.ops.rebuild-caches') }}">
                    @csrf
                    <button type="submit" class="btn btn--primary">Clear caches</button>
                </form>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel__head"><h2>Migration status</h2></div>
            <div class="admin-panel__body">
                <pre style="white-space:pre-wrap;max-height:24rem;overflow:auto">{{ $migrationStatus }}</pre>
            </div>
        </div>
    @endif
@endsection
