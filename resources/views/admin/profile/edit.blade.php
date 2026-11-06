@extends('layouts.dashboard')

@section('title', 'Your account & security')

@section('content')
    @php
        $initials = \Illuminate\Support\Str::of($user->name)->explode(' ')->filter()->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->implode('');
    @endphp
    <div class="page-header">
        <div class="page-header__text">
            <h1>Your account &amp; security</h1>
            <p class="page-header__desc">Your Super Admin sign-in security.</p>
        </div>
    </div>

    <div class="profile-identity">
        <span class="profile-avatar" aria-hidden="true">{{ $initials }}</span>
        <div class="profile-identity__text">
            <p class="profile-identity__name">{{ $user->name }}</p>
            <p class="profile-identity__meta">{{ $user->email }}</p>
        </div>
        <div class="profile-identity__badges">
            <span class="pill pill--draft">Super Admin</span>
            <span class="pill {{ $user->hasTwoFactorEnabled() ? 'pill--live' : 'pill--cancelled' }}">2FA {{ $user->hasTwoFactorEnabled() ? 'on' : 'off' }}</span>
        </div>
    </div>

    <div class="profile-layout">
      <div class="profile-col">
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
                <p class="muted">
                    Super Admin accounts are provisioned by CK Enterprises; contact the
                    platform team to change these details.
                </p>
            </div>
        </div>

        {{-- Change password --}}
        <div class="panel">
            <div class="panel__head"><h2>Change password</h2></div>
            @if (session('password_status'))
                <p class="status profile-status" data-status="saved">{{ session('password_status') }}</p>
            @endif
            <form method="POST" action="{{ route('admin.profile.password') }}" class="profile-form">
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="current_password">Current password</label>
                    <input type="password" name="current_password" id="current_password" required autocomplete="current-password">
                    @error('current_password')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="password">New password</label>
                        <input type="password" name="password" id="password" required autocomplete="new-password">
                        @error('password')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="password_confirmation">Confirm new password</label>
                        <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password">
                    </div>
                </div>
                <button type="submit" class="btn">Update password</button>
            </form>
        </div>
      </div>
      <div class="profile-col">
        {{-- Two-factor authentication --}}
        <div class="panel" id="two-factor">
            <div class="panel__head"><h2>Two-factor authentication</h2></div>

            @if (session('mfa_status'))
                <p class="status profile-status" data-status="saved">{{ session('mfa_status') }}</p>
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
                            <p class="muted">Your new recovery codes. Save them somewhere safe &mdash; each can be used once if you lose your device.</p>
                            <ul class="mfa-codes">
                                @foreach (session('mfa_recovery_codes') as $rc)
                                    <li>{{ $rc }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <form method="POST" action="{{ route('admin.profile.two-factor.recovery-codes') }}" class="profile-form">
                    @csrf
                    <p class="muted">Generate a fresh set of recovery codes. Your current codes will stop working.</p>
                    <div class="field">
                        <label for="rc_current_password">Current password</label>
                        <input type="password" name="current_password" id="rc_current_password" required autocomplete="current-password">
                        @error('current_password')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn btn-outline">Regenerate recovery codes</button>
                </form>

                <form method="POST" action="{{ route('admin.profile.two-factor.disable') }}" class="profile-form">
                    @csrf
                    @method('DELETE')
                    <p class="muted">Turn two-factor authentication off. We strongly recommend keeping it on for a Super Admin account.</p>
                    <div class="field">
                        <label for="disable_current_password">Current password</label>
                        <input type="password" name="current_password" id="disable_current_password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-outline btn-danger">Turn off two-factor</button>
                </form>

            @elseif ($user->two_factor_secret !== null)
                {{-- PENDING: enrolment started but not confirmed. --}}
                <div class="profile-form">
                    <p class="muted">
                        You've started setting up two-factor authentication but haven't
                        confirmed it yet. Continue where you left off to finish.
                    </p>
                    <a href="{{ route('admin.profile.two-factor.setup') }}" class="btn">Continue setup</a>
                </div>

            @else
                {{-- OFF: offer to enable. --}}
                <form method="POST" action="{{ route('admin.profile.two-factor.enable') }}" class="profile-form">
                    @csrf
                    <p class="muted">
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
      </div>
    </div>
@endsection
