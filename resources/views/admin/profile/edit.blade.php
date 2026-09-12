@extends('layouts.dashboard')

@section('title', 'Your account & security')

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
    .btn-danger { color: #b91c1c; border-color: #f0c2c2; }
    .btn-danger:hover { background: #fdecec; }
    .mfa-on { display: flex; align-items: flex-start; gap: 0.5rem; margin-top: 0; }
    .mfa-dot { flex: none; width: 10px; height: 10px; margin-top: 0.5rem; border-radius: 50%; background: #1b7a4b; }
    .mfa-codes-box { margin-top: 1rem; padding: 0.9rem 1rem; background: #f5f6fa; border: 1px solid var(--border); border-radius: 0.5rem; }
    .mfa-codes { list-style: none; margin: 0.25rem 0 0; padding: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 0.35rem 1rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.95rem; letter-spacing: 0.04em; }
</style>
@endpush

@section('content')
    <div class="page-head">
        <h1>Your account &amp; security</h1>
    </div>

    <div class="profile-grid">
        {{-- Account details (read-only for Super_Admins) --}}
        <div class="panel">
            <div class="panel__head"><h2>Account</h2></div>
            <div class="profile-form">
                <div class="field">
                    <label>Name</label>
                    <input type="text" value="{{ $user->name }}" disabled>
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" value="{{ $user->email }}" disabled>
                </div>
                <p class="muted" style="margin:0;">
                    Super Admin accounts are provisioned by CK Enterprises; contact the
                    platform team to change these details.
                </p>
            </div>
        </div>

        {{-- Two-factor authentication --}}
        <div class="panel" id="two-factor">
            <div class="panel__head"><h2>Two-factor authentication</h2></div>

            @if (session('mfa_status'))
                <p class="status" data-status="saved" style="margin: 1rem 1.25rem 0;">{{ session('mfa_status') }}</p>
            @endif

            @if ($user->hasTwoFactorEnabled())
                {{-- ENABLED --}}
                <div class="profile-form">
                    <p class="mfa-on">
                        <span class="mfa-dot" aria-hidden="true"></span>
                        Two-factor authentication is <strong>on</strong>. You'll be
                        asked for a code from your authenticator app each time you log in.
                    </p>

                    @if (session('mfa_recovery_codes'))
                        <div class="mfa-codes-box">
                            <p class="muted" style="margin-top:0;">Your new recovery codes. Save them somewhere safe &mdash; each can be used once if you lose your device.</p>
                            <ul class="mfa-codes">
                                @foreach (session('mfa_recovery_codes') as $rc)
                                    <li>{{ $rc }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <form method="POST" action="{{ route('admin.profile.two-factor.recovery-codes') }}" class="profile-form" style="border-top: 1px solid var(--border);">
                    @csrf
                    <p class="muted" style="margin-top:0;">Generate a fresh set of recovery codes. Your current codes will stop working.</p>
                    <div class="field">
                        <label for="rc_current_password">Current password</label>
                        <input type="password" name="current_password" id="rc_current_password" required autocomplete="current-password">
                        @error('current_password')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn btn-outline">Regenerate recovery codes</button>
                </form>

                <form method="POST" action="{{ route('admin.profile.two-factor.disable') }}" class="profile-form" style="border-top: 1px solid var(--border);">
                    @csrf
                    @method('DELETE')
                    <p class="muted" style="margin-top:0;">Turn two-factor authentication off. We strongly recommend keeping it on for a Super Admin account.</p>
                    <div class="field">
                        <label for="disable_current_password">Current password</label>
                        <input type="password" name="current_password" id="disable_current_password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-outline btn-danger">Turn off two-factor</button>
                </form>

            @elseif ($user->two_factor_secret !== null)
                {{-- PENDING: enrolment started but not confirmed. --}}
                <div class="profile-form">
                    <p class="muted" style="margin-top:0;">
                        You've started setting up two-factor authentication but haven't
                        confirmed it yet. Continue where you left off to finish.
                    </p>
                    <a href="{{ route('admin.profile.two-factor.setup') }}" class="btn">Continue setup</a>
                </div>

            @else
                {{-- OFF: offer to enable. --}}
                <form method="POST" action="{{ route('admin.profile.two-factor.enable') }}" class="profile-form">
                    @csrf
                    <p class="muted" style="margin-top:0;">
                        Add a second step to your login using an authenticator app. Enter
                        your current password to begin.
                    </p>
                    <div class="field">
                        <label for="enable_current_password">Current password</label>
                        <input type="password" name="current_password" id="enable_current_password" required autocomplete="current-password">
                        @error('current_password')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn">Set up two-factor</button>
                </form>
            @endif
        </div>

        {{-- Change password --}}
        <div class="panel">
            <div class="panel__head"><h2>Change password</h2></div>
            @if (session('password_status'))
                <p class="status" data-status="saved" style="margin: 1rem 1.25rem 0;">{{ session('password_status') }}</p>
            @endif
            <form method="POST" action="{{ route('admin.profile.password') }}" class="profile-form">
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
