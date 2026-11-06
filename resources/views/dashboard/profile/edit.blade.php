@extends('layouts.dashboard')

@section('title', 'Your profile')

@section('content')
    @php
        $initials = \Illuminate\Support\Str::of($user->name)->explode(' ')->filter()->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->implode('');
    @endphp
    <div class="page-header">
        <div class="page-header__text">
            <h1>Your profile</h1>
            <p class="page-header__desc">Manage your details, password, sign-in security and app access.</p>
        </div>
    </div>

    <div class="profile-identity">
        <span class="profile-avatar" aria-hidden="true">{{ $initials }}</span>
        <div class="profile-identity__text">
            <p class="profile-identity__name">{{ $user->name }}</p>
            <p class="profile-identity__meta">{{ $user->email }}</p>
        </div>
        <div class="profile-identity__badges">
            @if ($user->role)<span class="pill pill--draft">{{ \App\Models\User::roleLabel($user->role) }}</span>@endif
            <span class="pill {{ $user->hasTwoFactorEnabled() ? 'pill--live' : 'pill--cancelled' }}">2FA {{ $user->hasTwoFactorEnabled() ? 'on' : 'off' }}</span>
            @if (($appTokenCount ?? 0) > 0)<span class="pill pill--live">App enabled</span>@endif
        </div>
    </div>

    <div class="profile-layout">
      <div class="profile-col">
        {{-- Name + email --}}
        <div class="panel">
            <div class="panel__head"><h2>Account details</h2></div>
            @if (session('status'))
                <p class="status profile-status" data-status="saved">{{ session('status') }}</p>
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
                <p class="status profile-status" data-status="saved">{{ session('password_status') }}</p>
            @endif
            <form method="POST" action="{{ route('dashboard.profile.password') }}" class="profile-form">
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

                <form method="POST" action="{{ route('dashboard.profile.two-factor.recovery-codes') }}" class="profile-form">
                    @csrf
                    <p class="muted">Generate a fresh set of recovery codes. Your current codes will stop working.</p>
                    <div class="field">
                        <label for="rc_current_password">Current password</label>
                        <input type="password" name="current_password" id="rc_current_password" required autocomplete="current-password">
                        @error('current_password')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn btn-outline">Regenerate recovery codes</button>
                </form>

                <form method="POST" action="{{ route('dashboard.profile.two-factor.disable') }}" class="profile-form">
                    @csrf
                    @method('DELETE')
                    <p class="muted">Turn two-factor authentication off. We recommend keeping it on.</p>
                    <div class="field">
                        <label for="disable_current_password">Current password</label>
                        <input type="password" name="current_password" id="disable_current_password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-outline btn-danger">Turn off two-factor</button>
                </form>

            @elseif ($user->two_factor_secret !== null)
                {{-- PENDING: enrolment started but not yet confirmed. The QR,
                     recovery codes and confirm step live on the dedicated setup
                     screen. --}}
                <div class="profile-form">
                    <p class="muted">
                        You've started setting up two-factor authentication but haven't
                        confirmed it yet. Continue where you left off to finish.
                    </p>
                    <a href="{{ route('dashboard.profile.two-factor.setup') }}" class="btn">Continue setup</a>
                </div>

            @else
                {{-- OFF: offer to enable. Confirming the password starts enrolment
                     and takes the user to the dedicated setup screen. --}}
                <form method="POST" action="{{ route('dashboard.profile.two-factor.enable') }}" class="profile-form">
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

        {{-- Security & data --}}
        <div class="panel">
            <div class="panel__head"><h2>Security &amp; data</h2></div>
            @if (session('sessions_status'))
                <p class="status profile-status" data-status="saved">{{ session('sessions_status') }}</p>
            @endif

            <form method="POST" action="{{ route('dashboard.profile.logout-other-sessions') }}" class="profile-form">
                @csrf
                <h3 class="profile-sub">Other browser sessions</h3>
                <p class="muted">
                    Signed in on another device you no longer use? Sign out of every
                    other session while keeping this one active. Enter your current
                    password to confirm.
                </p>
                <div class="field">
                    <label for="sessions_current_password">Current password</label>
                    <input type="password" name="current_password" id="sessions_current_password" required autocomplete="current-password">
                    @error('current_password')<p class="error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn btn-outline">Sign out other sessions</button>
            </form>

            <form method="POST" action="{{ route('dashboard.profile.app-access.revoke') }}" class="profile-form"
                 
                  onsubmit="return confirm('Sign out of the iOS app on every device?');">
                @csrf
                <h3 class="profile-sub">iOS app <span class="profile-state {{ ($appTokenCount ?? 0) > 0 ? 'profile-state--on' : '' }}">{{ ($appTokenCount ?? 0) > 0 ? 'Enabled' : 'Not enabled' }}</span></h3>
                <p class="muted">
                    iOS app: @if (($appTokenCount ?? 0) > 0) <strong>enabled</strong> on {{ $appTokenCount }} {{ \Illuminate\Support\Str::plural('device', $appTokenCount) }}. @else not signed in on any device. @endif
                    Revoking signs the app out everywhere; you will need to sign in again.
                </p>
                <button type="submit" class="btn btn-outline">Revoke app access</button>
            </form>

            <div class="profile-form">
                <h3 class="profile-sub">Your data</h3>
                <p class="muted">
                    Download a copy of the personal data held on your account as a
                    JSON file.
                </p>
                <a class="btn btn-outline" href="{{ route('dashboard.profile.data') }}" download>
                    Download my account data
                </a>
            </div>
        </div>
      </div>
    </div>
@endsection
