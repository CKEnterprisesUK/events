<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-factor authentication &middot; Events by CK Enterprises</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@700&family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f1425; --purple: #674df3; --purple-dark: #5238d6; --teal: #30f0b6;
            --ink: #1b1f2e; --body: #454a5a; --muted: #838694;
            --heading-font: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --body-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: var(--body-font); color: var(--body); background: #eef0f5; line-height: 1.6; -webkit-font-smoothing: antialiased; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
        h1 { font-family: var(--heading-font); color: var(--ink); line-height: 1.25; margin: 0 0 .4rem; font-size: 1.6rem; }
        a { color: var(--purple); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .card { width: 100%; max-width: 420px; background: #fff; border-radius: 12px; padding: 2.5rem; box-shadow: 0 8px 30px rgba(15,20,37,.08); }
        .logo { display: inline-flex; align-items: baseline; gap: .4rem; font-family: 'Cabin Sketch', cursive; font-weight: 700; color: var(--ink); margin-bottom: 1.75rem; line-height: 1; }
        .logo .events { font-size: 1.4rem; color: var(--purple); }
        .logo .by { font-family: var(--body-font); font-weight: 500; font-size: .72rem; letter-spacing: .04em; color: var(--muted); text-transform: uppercase; }
        .logo .ck { font-size: 1.4rem; color: var(--ink); }
        .sub { color: var(--muted); margin: 0 0 1.75rem; }
        .alert { background: #fdecec; border: 1px solid #f5c2c2; color: #a12626; border-radius: 6px; padding: .8rem 1rem; font-size: .92rem; margin-bottom: 1.5rem; }
        .field { margin-bottom: 1.25rem; }
        .field label { display: block; font-weight: 600; color: var(--ink); margin-bottom: .45rem; font-size: .93rem; }
        .field input { width: 100%; padding: .75rem .9rem; border: 1px solid #d5d7e0; border-radius: 6px; font-size: 1rem; font-family: var(--body-font); color: var(--ink); background: #fff; }
        .field input:focus { outline: none; border-color: var(--purple); box-shadow: 0 0 0 3px rgba(103,77,243,.12); }
        .field .hint { color: var(--muted); font-size: .85rem; margin: .4rem 0 0; }
        .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; padding: .8rem 1.5rem; border-radius: 6px; font-weight: 600; font-size: 1rem; font-family: var(--body-font); cursor: pointer; border: none; background: var(--purple); color: #fff; transition: background .15s ease; }
        .btn:hover { background: var(--purple-dark); }
        .divider { display: flex; align-items: center; gap: .75rem; margin: 1.75rem 0; color: var(--muted); font-size: .82rem; text-transform: uppercase; letter-spacing: .05em; }
        .divider::before, .divider::after { content: ""; flex: 1; height: 1px; background: #e6e7ec; }
        details.recovery summary { cursor: pointer; color: var(--purple); font-weight: 600; font-size: .93rem; }
        details.recovery[open] summary { margin-bottom: 1rem; }
        .alt { text-align: center; margin-top: 1.75rem; font-size: .95rem; color: var(--muted); }
    </style>
</head>
<body>
    <div class="card">
        <a class="logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises">
            <span class="events">Events</span>
            <span class="by">by</span>
            <span class="ck">CK Enterprises</span>
        </a>

        <h1>Two-step verification</h1>
        <p class="sub">Enter the 6-digit code from your authenticator app to finish signing in.</p>

        @if ($errors->any())
            <div class="alert" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('two-factor.challenge.store') }}">
            @csrf
            <div class="field">
                <label for="code">Authentication code</label>
                <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                       autofocus pattern="[0-9 ]*" placeholder="123456">
            </div>
            <button type="submit" class="btn">Verify</button>
        </form>

        <div class="divider">or</div>

        <details class="recovery">
            <summary>Use a recovery code instead</summary>
            <p class="sub" style="margin-top:1rem;">
                Lost your device? Enter one of the one-time recovery codes you saved
                when you set up two-factor authentication.
            </p>
            <form method="POST" action="{{ route('two-factor.challenge.store') }}">
                @csrf
                <div class="field">
                    <label for="recovery_code">Recovery code</label>
                    <input id="recovery_code" type="text" name="recovery_code"
                           autocomplete="off" placeholder="abcd-1234">
                </div>
                <button type="submit" class="btn">Verify recovery code</button>
            </form>
        </details>

        <p class="alt">
            <a href="{{ route('login') }}">Back to log in</a>
        </p>
    </div>
</body>
</html>
