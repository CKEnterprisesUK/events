@extends('layouts.dashboard')

@section('title', 'Users')

@section('content')
    <section>
        <h1>Users</h1>

        @if (session('status'))
            <p class="status">{{ session('status') }}</p>
        @endif

        @error('role')
            <p class="error">{{ $message }}</p>
        @enderror
        @error('user')
            <p class="error">{{ $message }}</p>
        @enderror

        <h2>Team</h2>
        @if ($users->isEmpty())
            <p>No users yet.</p>
        @else
            <ul>
                @foreach ($users as $user)
                    <li>{{ $user->name }} ({{ $user->email }}) &mdash; {{ ucfirst($user->role) }}</li>
                @endforeach
            </ul>
        @endif

        <h2>Pending invitations</h2>
        @if ($invitations->isEmpty())
            <p>No pending invitations.</p>
        @else
            <ul>
                @foreach ($invitations as $invitation)
                    <li>{{ $invitation->email }} &mdash; {{ ucfirst($invitation->role) }}</li>
                @endforeach
            </ul>
        @endif

        <h2>Invite a user</h2>
        <form method="POST" action="{{ route('dashboard.users.invite') }}">
            @csrf

            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required>
                @error('email')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    @foreach (\App\Models\User::INVITABLE_ROLES as $role)
                        <option value="{{ $role }}">{{ ucfirst($role) }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="btn">Send invitation</button>
        </form>
    </section>
@endsection
