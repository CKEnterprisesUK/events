<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your email &middot; Events by CK Enterprises</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@700&family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f1425;
            --purple: #674df3;
            --purple-dark: #5238d6;
            --teal: #30f0b6;
            --ink: #1b1f2e;
            --body: #454a5a;
            --muted: #838694;
            --heading-font: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --body-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: var(--body-font); color: var(--body); background: #eef0f5; line-height: 1.6;
            padding: 2rem; -webkit-font-smoothing: antialiased;
        }
        h1 { font-family: var(--heading-font); color: var(--ink); line-height: 1.25; margin: 0 0 .4rem; font-size: 1.7rem; }
        a { color: var(--purple); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .card { width: 100%; max-width: 460px; background: #fff; border-radius: 10px; padding: 2.5rem; box-shadow: 0 10px 40px rgba(15,20,37,.08); }
        .logo { display: inline-flex; align-items: baseline; gap: .4rem; font-family: 'Cabin Sketch', cursive; font-weight: 700; color: var(--ink); margin-bottom: 1.75rem; line-height: 1; }
        .logo .events { font-size: 1.5rem; color: var(--purple); }
        .logo .by { font-family: var(--body-font); font-weight: 500; font-size: .75rem; letter-spacing: .04em; color: var(--muted); text-transform: uppercase; }
        .logo .ck { font-size: 1.5rem; color: var(--ink); }
        .sub { color: var(--body); margin: 0 0 1.5rem; }
        .alert { background: #eafaf1; border: 1px solid #a7e0c3; color: #1b7a4b; border-radius: 6px; padding: .8rem 1rem; font-size: .92rem; margin-bottom: 1.5rem; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; width: 100%;
            padding: .8rem 1.5rem; border-radius: 6px; font-weight: 600; font-size: 1rem;
            font-family: var(--body-font); cursor: pointer; border: none;
            background: var(--purple); color: #fff; transition: background .15s ease; margin-bottom: 1rem;
        }
        .btn:hover { background: var(--purple-dark); }
        .actions { display: flex; align-items: center; justify-content: space-between; gap: 1rem; font-size: .95rem; }
        .actions form { margin: 0; }
        .link-btn { background: none; border: none; color: var(--purple); font: inherit; cursor: pointer; padding: 0; }
        .link-btn:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <a class="logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises">
            <span class="events">Events</span>
            <span class="by">by</span>
            <span class="ck">CK Enterprises</span>
        </a>

        <h1>Verify your email</h1>
        <p class="sub">
            Thanks for signing up. We've sent a verification link to
            <strong>{{ auth()->user()->email }}</strong>. Click it to activate your
            account, then you can sign in to your dashboard.
        </p>

        @if (session('status'))
            <div class="alert" role="status">{{ session('status') }}</div>
        @endif

        <p class="sub">Didn't get the email? Check your spam folder, or request a fresh link below.</p>

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn">Resend verification email</button>
        </form>

        <div class="actions">
            <span>Wrong account?</span>
            <form method="POST" action="{{ url('/logout') }}">
                @csrf
                <button type="submit" class="link-btn">Log out</button>
            </form>
        </div>
    </div>
</body>
</html>
