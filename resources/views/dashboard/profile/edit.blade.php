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
    .btn-danger { color: #b91c1c; border-color: #f0c2c2; }
    .btn-danger:hover { background: #fdecec; }
    .mfa-on { display: flex; align-items: flex-start; gap: 0.5rem; margin-top: 0; }
    .mfa-dot { flex: none; width: 10px; height: 10px; margin-top: 0.5rem; border-radius: 50%; background: #1b7a4b; }
    .mfa-qr { display: flex; justify-content: center; padding: 1rem; background: #fff; border: 1px solid var(--border); border-radius: 0.5rem; margin: 0.5rem 0 1rem; }
    .mfa-qr svg, .mfa-qr img { width: 200px; height: 200px; }
    .mfa-secret { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1rem; letter-spacing: 0.08em; background: #f5f6fa; border: 1px solid var(--border); border-radius: 0.4rem; padding: 0.6rem 0.75rem; word-break: break-all; }
    .mfa-codes-box { margin-top: 1rem; padding: 0.9rem 1rem; background: #f5f6fa; border: 1px solid var(--border); border-radius: 0.5rem; }
    .mfa-codes { list-style: none; margin: 0.25rem 0 0; padding: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 0.35rem 1rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.95rem; letter-spacing: 0.04em; }
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

        {{-- Two-factor authentication --}}
        <div class="panel" id="two-factor">
            <div class="panel__head"><h2>Two-factor authentication</h2></div>

            @if (session('mfa_status'))
                <p class="status" data-status="saved" style="margin: 1rem 1.25rem 0;">{{ session('mfa_status') }}</p>
            @endif

            @php($mfaSetup = session('mfa_setup'))

            @if ($user->hasTwoFactorEnabled() && ! $mfaSetup)
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

                <form method="POST" action="{{ route('dashboard.profile.two-factor.recovery-codes') }}" class="profile-form" style="border-top: 1px solid var(--border);">
                    @csrf
                    <p class="muted" style="margin-top:0;">Generate a fresh set of recovery codes. Your current codes will stop working.</p>
                    <div class="field">
                        <label for="rc_current_password">Current password</label>
                        <input type="password" name="current_password" id="rc_current_password" required autocomplete="current-password">
                        @error('current_password')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn btn-outline">Regenerate recovery codes</button>
                </form>

                <form method="POST" action="{{ route('dashboard.profile.two-factor.disable') }}" class="profile-form" style="border-top: 1px solid var(--border);">
                    @csrf
                    @method('DELETE')
                    <p class="muted" style="margin-top:0;">Turn two-factor authentication off. We recommend keeping it on.</p>
                    <div class="field">
                        <label for="disable_current_password">Current password</label>
                        <input type="password" name="current_password" id="disable_current_password" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-outline btn-danger">Turn off two-factor</button>
                </form>

            @elseif ($mfaSetup)
                {{-- PENDING CONFIRMATION: secret generated, awaiting first code --}}
                <div class="profile-form">
                    <p class="muted" style="margin-top:0;">
                        Scan this QR code with an authenticator app (Google Authenticator,
                        Authy, 1Password, etc.), then enter the 6-digit code it shows to
                        turn on two-factor authentication.
                    </p>

                    @if (! empty($mfaSetup['qr']))
                        <div class="mfa-qr">
                            <img src="{{ $mfaSetup['qr'] }}" alt="Two-factor authentication QR code" width="200" height="200">
                        </div>
                    @endif

                    @if (! empty($mfaSetup['secret']))
                        <p class="muted">Can't scan? Enter this key manually:</p>
                        <p class="mfa-secret">{{ $mfaSetup['secret'] }}</p>
                    @endif

                    @if (! empty($mfaSetup['recovery_codes']))
                        <div class="mfa-codes-box">
                            <p class="muted" style="margin-top:0;"><strong>Save your recovery codes.</strong> Each can be used once to log in if you lose your device.</p>
                            <ul class="mfa-codes">
                                @foreach ($mfaSetup['recovery_codes'] as $rc)
                                    <li>{{ $rc }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <form method="POST" action="{{ route('dashboard.profile.two-factor.confirm') }}" class="profile-form" style="border-top: 1px solid var(--border);">
                    @csrf
                    <div class="field">
                        <label for="mfa_code">Enter the 6-digit code</label>
                        <input type="text" name="code" id="mfa_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" placeholder="123456" required>
                        @error('code')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn">Confirm &amp; turn on</button>
                </form>

            @else
                {{-- OFF: offer to enable --}}
                <form method="POST" action="{{ route('dashboard.profile.two-factor.enable') }}" class="profile-form">
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

        {{-- Security & data --}}
        <div class="panel">
            <div class="panel__head"><h2>Security &amp; data</h2></div>
            @if (session('sessions_status'))
                <p class="status" data-status="saved" style="margin: 1rem 1.25rem 0;">{{ session('sessions_status') }}</p>
            @endif

            <form method="POST" action="{{ route('dashboard.profile.logout-other-sessions') }}" class="profile-form">
                @csrf
                <p class="muted" style="margin-top: 0;">
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

            <div class="profile-form" style="border-top: 1px solid var(--border);">
                <p class="muted" style="margin-top: 0;">
                    Download a copy of the personal data held on your account as a
                    JSON file.
                </p>
                <a class="btn btn-outline" href="{{ route('dashboard.profile.data') }}" download>
                    Download my account data
                </a>
            </div>
        </div>
    </div>
@endsection
