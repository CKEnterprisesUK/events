@extends('layouts.dashboard')
@section('title', 'Super-Admin — Build / deploy')
@section('content')
    <div class="admin-head">
        <div>
            <h1>Build / deploy</h1>
            <p>Apply database migrations and clear caches in-process after a code pull — no SSH, no terminal, no cPanel deploy tasks needed.</p>
        </div>
    </div>

    @if (session('ops_notice'))
        <div class="admin-panel">
            <div class="admin-panel__body">
                <span class="admin-pill admin-pill--suspended">Action needed</span>
                <p>{{ session('ops_notice') }}</p>
            </div>
        </div>
    @endif

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
                Environment <code>{{ app()->environment() }}</code>@if ($isProduction) <span class="admin-pill admin-pill--suspended">Production</span>@endif,
                connection <code>{{ $connection }}</code>, database <code>{{ $database }}</code>.
                @if ($connection !== 'mysql' && $connection !== 'mariadb')
                    <span class="admin-pill admin-pill--suspended">Not MySQL — check .env</span>
                @endif
            </p>
            <p class="muted">Row counts:
                @foreach ($counts as $table => $count)
                    <code>{{ $table }}={{ $count === null ? 'no table' : $count }}</code>@if (! $loop->last), @endif
                @endforeach
            </p>
            @if (! empty($pendingMigrations))
                <p>
                    <span class="admin-pill admin-pill--suspended">{{ count($pendingMigrations) }} pending migration{{ count($pendingMigrations) === 1 ? '' : 's' }}</span>
                </p>
                <ul class="muted" style="margin:.25rem 0 0 1rem">
                    @foreach ($pendingMigrations as $name)
                        <li><code>{{ $name }}</code></li>
                    @endforeach
                </ul>
            @else
                <p><span class="admin-pill admin-pill--active">Schema up to date</span></p>
            @endif
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Actions</h2></div>
        <div class="admin-panel__body">
            <p>
                <strong>Run migrations</strong> — applies any pending migrations
                (<code>migrate --force</code>). Forward-only; it never drops data.
            </p>

            @if ($isProduction)
                <div class="admin-panel" style="background:var(--surface-2, #fff8f0);border:1px solid var(--border)">
                    <div class="admin-panel__body">
                        <p>
                            <strong>You are on production.</strong> Migrations run against the
                            <em>live customer database</em>. Before running:
                        </p>
                        <ol style="margin:.25rem 0 .75rem 1.25rem">
                            <li>Take a database backup first — cPanel → <em>Backup</em> (or phpMyAdmin → <em>Export</em>).</li>
                            <li>Confirm this schema change was already tested on pre-prod.</li>
                        </ol>
                        <form method="POST" action="{{ route('admin.ops.migrate') }}"
                              onsubmit="return confirm('Run migrations against the PRODUCTION database now? Make sure you have taken a backup.');"
                              style="margin-bottom:1.5rem">
                            @csrf
                            <label style="display:block;margin-bottom:.5rem">
                                <input type="checkbox" name="ack_backup" value="1" required>
                                I have taken a database backup and tested this on pre-prod.
                            </label>
                            <button type="submit" class="btn btn--primary">Run migrations (production)</button>
                        </form>
                    </div>
                </div>
            @else
                <form method="POST" action="{{ route('admin.ops.migrate') }}" style="margin-bottom:1.5rem">
                    @csrf
                    <button type="submit" class="btn btn--primary">Run migrations</button>
                </form>
            @endif

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

            @if (! $reseedBlocked)
                <hr style="margin:1.5rem 0;border:none;border-top:1px solid var(--border)">

                <p>
                    <strong>Rebuild sample data</strong> — seeds prod-like data and the test logins
                    (<code>super@preprod.test</code> / <code>owner@preprod.test</code>, password <code>password</code>).
                    Seeds only when empty unless you tick "wipe first". <em>Pre-prod only.</em>
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
            @endif
        </div>
    </div>

    <div class="admin-panel">
        <div class="admin-panel__head"><h2>Migration status</h2></div>
        <div class="admin-panel__body">
            <pre style="white-space:pre-wrap;max-height:24rem;overflow:auto">{{ $migrationStatus }}</pre>
        </div>
    </div>
@endsection
