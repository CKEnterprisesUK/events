@extends('layouts.dashboard')

@section('title', 'Your profile')

@push('head')
<style>
    .profile-grid { display: grid; gap: 1.5rem; max-width: 560px; }
    .profile-form { padding: 1.25rem; }
    .profile-form .field { margin-bottom: 1rem; }
    .profile-form label { display: block; font-weight: 600; margin-bottom: 0.3rem; }
    .profile-form input {
        width: 100%; padding: 0.55rem 0.7rem; border: 1px solid var(--border); border-radius: 0.5rem;
    }
    .profile-form .error { color: #b91c1c; font-size: 0.85rem; margin: 0.25rem 0 0; }
</style>
@endpush

@section('content')
    <div class="page-head">
        <h1>Your profile</h1>
    </div>

    <div class="profile-grid">
        {{-- Name + email --}}
        <div class="panel">
            <div class="panel__head"><h2>Account details</h2></div>
            @if (session('status'))
                <p class="status" data-status="saved" style="margin: 1rem 1.25rem 0;">{{ session('status') }}</p>
            @endif
            <form method="POST" action="{{ route('dashboard.profile.update') }}" class="profile-form">
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="name">Name</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required>
                    @error('name')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" required autocomplete="username">
                    @error('email')<p class="error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn">Save changes</button>
            </form>
        </div>

        {{-- Change password --}}
        <div class="panel">
            <div class="panel__head"><h2>Change password</h2></div>
            @if (session('password_status'))
                <p class="status" data-status="saved" style="margin: 1rem 1.25rem 0;">{{ session('password_status') }}</p>
            @endif
            <form method="POST" action="{{ route('dashboard.profile.password') }}" class="profile-form">
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="current_password">Current password</label>
                    <input type="password" name="current_password" id="current_password" required autocomplete="current-password">
                    @error('current_password')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="password">New password</label>
                    <input type="password" name="password" id="password" required autocomplete="new-password">
                    @error('password')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm new password</label>
                    <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn">Update password</button>
            </form>
        </div>
    </div>
@endsection
