@extends('layouts.dashboard')

@section('title', 'Super-Admin — Users')

@push('head')
<style>
    .user-filters { display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: flex-end; margin: 0 0 1rem; }
    .user-filters .field { display: flex; flex-direction: column; gap: 0.25rem; }
    .user-filters label { font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.03em; }
    .user-filters input, .user-filters select {
        padding: 0.45rem 0.6rem; border: 1px solid var(--border); border-radius: 0.4rem; font-size: 0.9rem; min-width: 12rem;
    }
    .user-filters button { padding: 0.5rem 1rem; }
    .user-status-pill { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; padding: 0.1rem 0.5rem; border-radius: 999px; }
    .user-status-pill.is-active { background: #ecfdf5; color: #047857; }
    .user-status-pill.is-disabled { background: #fef2f2; color: #b91c1c; }
    .user-role-tag { font-size: 0.78rem; color: #6b7280; }
    .admin-table td .cell-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; }
</style>
@endpush

@section('content')
    <div class="admin-head">
        <div>
            <h1>Users</h1>
            <p>Every team member across every company. Super admins are managed separately.</p>
        </div>
    </div>

    @if (session('status'))
        <p class="status" role="status">{{ session('status') }}</p>
    @endif

    <form method="GET" action="{{ route('admin.users.index') }}" class="user-filters">
        <div class="field">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="{{ $search }}" placeholder="Name or email">
        </div>
        <div class="field">
            <label for="company">Company</label>
            <select id="company" name="company">
                <option value="">All companies</option>
                @foreach ($companies as $c)
                    <option value="{{ $c->id }}" @selected($companyId === $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="">All roles</option>
                @foreach (\App\Models\User::ROLES as $r)
                    <option value="{{ $r }}" @selected($role === $r)>{{ \App\Models\User::roleLabel($r) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <option value="active" @selected($status === 'active')>Active</option>
                <option value="disabled" @selected($status === 'disabled')>Disabled</option>
            </select>
        </div>
        <button type="submit" class="btn">Filter</button>
        <a class="btn btn-outline" href="{{ route('admin.users.index') }}">Clear</a>
    </form>

    <div class="admin-panel">
        <div class="admin-panel__head">
            <h2>{{ number_format($users->total()) }} {{ Str::plural('user', $users->total()) }}</h2>
        </div>
        @if ($users->isEmpty())
            <div class="admin-empty">No users match those filters.</div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Email</th>
                        <th scope="col">Company</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr data-user-id="{{ $user->id }}">
                            <td class="cell-strong">{{ $user->name }}</td>
                            <td>
                                {{ $user->email }}
                                @unless ($user->hasVerifiedEmail())
                                    <span class="cell-dim">(unverified)</span>
                                @endunless
                            </td>
                            <td>
                                @if ($user->company)
                                    <a class="panel__link" href="{{ route('admin.clients.show', $user->company_id) }}">{{ $user->company->name }}</a>
                                @else
                                    <span class="cell-dim">—</span>
                                @endif
                            </td>
                            <td><span class="user-role-tag">{{ \App\Models\User::roleLabel($user->role) }}</span></td>
                            <td>
                                <span class="user-status-pill {{ $user->isDisabled() ? 'is-disabled' : 'is-active' }}">
                                    {{ $user->isDisabled() ? 'Disabled' : 'Active' }}
                                </span>
                            </td>
                            <td class="num">
                                @if ($user->company)
                                    <a class="panel__link" href="{{ route('admin.clients.show', $user->company_id) }}#user-{{ $user->id }}">Manage</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="pager">{{ $users->links() }}</div>
        @endif
    </div>
@endsection
