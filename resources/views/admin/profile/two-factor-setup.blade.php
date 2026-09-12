@extends('layouts.dashboard')

@section('title', 'Set up two-factor authentication')

@push('head')
<style>
    .mfa-setup { display: grid; gap: 1.5rem; max-width: 560px; }
    .mfa-setup .panel__body { padding: 1.25rem; }
    .mfa-qr { display: flex; justify-content: center; padding: 1rem; background: #fff; border: 1px solid var(--border); border-radius: 0.5rem; margin: 0.5rem 0 1rem; }
    .mfa-qr img { width: 220px; height: 220px; display: block; }
    .mfa-secret { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1rem; letter-spacing: 0.08em; background: #f5f6fa; border: 1px solid var(--border); border-radius: 0.4rem; padding: 0.6rem 0.75rem; word-break: break-all; }
    .mfa-codes-box { margin-top: 1rem; padding: 0.9rem 1rem; background: #f5f6fa; border: 1px solid var(--border); border-radius: 0.5rem; }
    .mfa-codes { list-style: none; margin: 0.5rem 0 0; padding: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 0.35rem 1rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.95rem; letter-spacing: 0.04em; }
    .mfa-form .field { margin-bottom: 1rem; }
    .mfa-form label { display: block; font-weight: 600; margin-bottom: 0.3rem; }
    .mfa-form input { width: 100%; padding: 0.55rem 0.7rem; border: 1px solid var(--border); border-radius: 0.5rem; }
    .mfa-form .error { color: #b91c1c; font-size: 0.85rem; margin: 0.25rem 0 0; }
    .mfa-actions { display: flex; gap: 0.75rem; align-items: center; }
</style>
@endpush

@section('content')
    <div class="page-head">
        <h1>Set up two-factor authentication</h1>
        <a href="{{ route('admin.profile.edit') }}#two-factor" class="btn btn-outline">Cancel</a>
    </div>

    <div class="mfa-setup">
        <div class="panel">
            <div class="panel__head"><h2>1. Scan the QR code</h2></div>
            <div class="panel__body">
                <p class="muted" style="margin-top:0;">
                    Open your authenticator app (Google Authenticator, Authy, 1Password, etc.)
                    and scan this code to add your account.
                </p>

                <div class="mfa-qr">
                    <img src="{{ route('admin.profile.two-factor.qr') }}"
                         alt="Two-factor authentication QR code" width="220" height="220">
                </div>

                <p class="muted">Can't scan? Enter this key manually instead:</p>
                <p class="mfa-secret">{{ $secret }}</p>
            </div>
        </div>

        <div class="panel">
            <div class="panel__head"><h2>2. Save your recovery codes</h2></div>
            <div class="panel__body">
                <p class="muted" style="margin-top:0;">
                    Store these somewhere safe. Each code can be used once to log in
                    if you ever lose access to your authenticator app.
                </p>
                <div class="mfa-codes-box">
                    <ul class="mfa-codes">
                        @foreach ($recoveryCodes as $rc)
                            <li>{{ $rc }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__head"><h2>3. Confirm the code</h2></div>
            <div class="panel__body">
                <p class="muted" style="margin-top:0;">
                    Enter the current 6-digit code shown in your authenticator app to
                    finish turning on two-factor authentication.
                </p>
                <form method="POST" action="{{ route('admin.profile.two-factor.confirm') }}" class="mfa-form">
                    @csrf
                    <div class="field">
                        <label for="code">6-digit code</label>
                        <input type="text" name="code" id="code" inputmode="numeric"
                               autocomplete="one-time-code" pattern="[0-9 ]*" placeholder="123456" required autofocus>
                        @error('code')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="mfa-actions">
                        <button type="submit" class="btn">Confirm &amp; turn on</button>
                        <a href="{{ route('admin.profile.edit') }}#two-factor" class="muted">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
