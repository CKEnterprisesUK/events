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
    @error('role') <p class="alert-error">{{ $message }}</p> @enderror
    @error('user') <p class="alert-error">{{ $message }}</p> @enderror

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
                            <option value="{{ $role }}">{{ ucfirst($role) }}</option>
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
                                            <option value="{{ $role }}" @selected($member->role === $role)>{{ ucfirst($role) }}</option>
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
                <thead><tr><th>Email</th><th>Role</th></tr></thead>
                <tbody>
                    @foreach ($invitations as $invitation)
                        <tr>
                            <td>{{ $invitation->email }}</td>
                            <td><span class="pill pill--draft">{{ ucfirst($invitation->role) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection

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
