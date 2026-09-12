<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset password &middot; Events by CK Enterprises</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@700&family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    @include('auth.passwords._styles')
</head>
<body>
    <main class="auth-narrow">
        <div class="form-card">
            <a class="mobile-logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises">
                <span class="events">Events</span>
                <span class="by">by</span>
                <span class="ck">CK Enterprises</span>
            </a>

            <h1>Choose a new password</h1>
            <p class="sub">Enter and confirm your new password below.</p>

            @if ($errors->any())
                <div class="alert" role="alert">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username">
                </div>
                <div class="field">
                    <label for="password">New password</label>
                    <input id="password" type="password" name="password" required autocomplete="new-password" minlength="8">
                    <p class="hint">At least 8 characters. Tip: three random words make a strong, memorable password &mdash; for example <em>coffee-tractor-lantern</em>.</p>
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm new password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn">Reset password</button>
            </form>

            <p class="alt"><a href="{{ route('login') }}">Back to log in</a></p>
        </div>
    </main>
</body>
</html>
