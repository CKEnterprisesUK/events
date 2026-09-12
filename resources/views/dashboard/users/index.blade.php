@extends('layouts.dashboard')

@section('title', 'Team')

@section('content')
    <div class="page-head">
        <h1>Team</h1>
        <div class="page-head__actions">
            <button type="button" class="btn" data-toggle="invite">Invite user</button>
        </div>
    </div>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="alert-error">{{ session('error') }}</p>
    @endif
    @error('role') <p class="alert-error">{{ $message }}</p> @enderror
    @error('user') <p class="alert-error">{{ $message }}</p> @enderror

    {{-- Role capabilities --}}
    <div class="panel">
        <div class="panel__head"><h2>What each role can do</h2></div>
        <p class="role-matrix__intro">
            Only Owners and Admins can handle GDPR requests and connect Stripe for
            payments. Owners alone manage company settings, Stripe fees, billing
            and the team. Box office staff run events, ticketing and orders without
            access to company settings.
        </p>
        <div class="role-matrix__scroll">
            <table class="data-table role-matrix">
                <thead>
                    <tr>
                        <th>Capability</th>
                        @foreach (\App\Models\User::ROLES as $role)
                            <th class="num" title="{{ \App\Models\User::ROLE_META[$role]['description'] ?? '' }}">
                                {{ \App\Models\User::roleLabel($role) }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($capabilityMatrix as $capability)
                        <tr>
                            <td><span class="cell-strong">{{ $capability['label'] }}</span></td>
                            @foreach (\App\Models\User::ROLES as $role)
                                <td class="num">
                                    @if ($capability['roles'][$role])
                                        <span class="role-matrix__yes" title="Allowed" aria-label="Allowed">&#10003;</span>
                                    @else
                                        <span class="role-matrix__no" title="Not allowed" aria-label="Not allowed">&ndash;</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Invite form --}}
    <div class="panel form-panel" id="invite" @if (! $errors->has('email')) hidden @endif>
        <div class="panel__head"><h2>Invite a user</h2></div>
        <form method="POST" action="{{ route('dashboard.users.invite') }}" class="stack">
            @csrf
            <div class="field-row">
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" required value="{{ old('email') }}">
                    @error('email') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="role">Role</label>
                    <select id="role" name="role" required>
                        @foreach (\App\Models\User::INVITABLE_ROLES as $role)
                            <option value="{{ $role }}">{{ \App\Models\User::roleLabel($role) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn">Send invitation</button>
                <button type="button" class="btn btn-outline" data-toggle="invite">Cancel</button>
            </div>
        </form>
    </div>

    {{-- Team members --}}
    <div class="panel">
        <div class="panel__head"><h2>Members</h2></div>
        @if ($users->isEmpty())
            <div class="empty"><p>No users yet.</p></div>
        @else
            <table class="data-table">
                <thead>
                    <tr><th>Name</th><th>Email</th><th style="width:160px;">Role</th><th class="num">Actions</th></tr>
                </thead>
                <tbody>
                    @foreach ($users as $member)
                        @php $isSelf = $member->id === auth()->id(); @endphp
                        <tr>
                            <td>
                                <span class="cell-strong">{{ $member->name }}</span>
                                @if ($isSelf) <span class="cell-dim">You</span> @endif
                            </td>
                            <td>{{ $member->email }}</td>
                            <td>
                                <form method="POST" action="{{ route('dashboard.users.role', $member) }}" class="inline-form role-form">
                                    @csrf
                                    @method('PUT')
                                    <select name="role" onchange="this.form.submit()" @if ($isSelf) disabled title="You can't change your own role" @endif>
                                        @foreach (\App\Models\User::ROLES as $role)
                                            <option value="{{ $role }}" @selected($member->role === $role)>{{ \App\Models\User::roleLabel($role) }}</option>
                                        @endforeach
                                    </select>
                                </form>
                            </td>
                            <td class="num">
                                @if ($isSelf)
                                    <span class="cell-dim">—</span>
                                @else
                                    <form method="POST" action="{{ route('dashboard.users.destroy', $member) }}" class="inline-form"
                                          onsubmit="return confirm('Remove {{ $member->name }} from the team? This revokes their access.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline btn-sm">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Pending invitations --}}
    <div class="panel">
        <div class="panel__head"><h2>Pending invitations</h2></div>
        @if ($invitations->isEmpty())
            <div class="empty"><p>No pending invitations.</p></div>
        @else
            <table class="data-table">
                <thead><tr><th>Email</th><th>Role</th><th class="num">Actions</th></tr></thead>
                <tbody>
                    @foreach ($invitations as $invitation)
                        <tr>
                            <td>{{ $invitation->email }}</td>
                            <td><span class="pill pill--draft">{{ \App\Models\User::roleLabel($invitation->role) }}</span></td>
                            <td class="num invite-actions">
                                <form method="POST" action="{{ route('dashboard.users.invitations.resend', $invitation) }}" class="inline-form">
                                    @csrf
                                    <button type="submit" class="btn btn-outline btn-sm">Resend</button>
                                </form>
                                <form method="POST" action="{{ route('dashboard.users.invitations.cancel', $invitation) }}" class="inline-form"
                                      onsubmit="return confirm('Cancel the invitation to {{ $invitation->email }}? Their invite link will stop working.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline btn-sm">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection

@push('head')
<style>
    .role-matrix__intro { margin: 0; padding: 1rem 1.25rem; font-size: 0.88rem; color: var(--muted); border-bottom: 1px solid var(--border); }
    .role-matrix__scroll { overflow-x: auto; }
    .role-matrix th.num, .role-matrix td.num { text-align: center; white-space: nowrap; }
    .role-matrix thead th { font-size: 0.85rem; }
    .role-matrix__yes { color: #047857; font-weight: 700; }
    .role-matrix__no { color: var(--border); }
    .invite-actions { display: flex; gap: 0.5rem; justify-content: flex-end; }
</style>
@endpush

@push('scripts')
<script>
    document.querySelectorAll('[data-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var el = document.getElementById(btn.getAttribute('data-toggle'));
            if (el) { el.hidden = !el.hidden; if (!el.hidden) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
        });
    });
</script>
@endpush
