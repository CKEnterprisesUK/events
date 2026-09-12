<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Secure your account &middot; Events by CK Enterprises</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@700&family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --purple: #674df3; --purple-dark: #5238d6; --teal: #30f0b6;
            --ink: #1b1f2e; --body: #454a5a; --muted: #838694;
            --heading-font: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --body-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: var(--body-font); color: var(--body); background: #eef0f5; line-height: 1.6; -webkit-font-smoothing: antialiased; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
        h1 { font-family: var(--heading-font); color: var(--ink); line-height: 1.25; margin: 0 0 .5rem; font-size: 1.55rem; }
        a { color: var(--purple); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .card { width: 100%; max-width: 460px; background: #fff; border-radius: 12px; padding: 2.5rem; box-shadow: 0 8px 30px rgba(15,20,37,.08); text-align: center; }
        .shield { width: 56px; height: 56px; margin: 0 auto 1.25rem; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: rgba(103,77,243,.1); color: var(--purple); }
        .shield svg { width: 30px; height: 30px; }
        .sub { color: var(--body); margin: 0 0 1.75rem; }
        .btn { display: inline-flex; align-items: center; justify-content: center; width: 100%; padding: .8rem 1.5rem; border-radius: 6px; font-weight: 600; font-size: 1rem; font-family: var(--body-font); cursor: pointer; border: none; background: var(--purple); color: #fff; transition: background .15s ease; text-decoration: none; }
        .btn:hover { background: var(--purple-dark); text-decoration: none; }
        .btn-plain { background: transparent; color: var(--body); border: 1px solid #d5d7e0; }
        .btn-plain:hover { background: #f5f6fa; color: var(--ink); }
        .actions { display: grid; gap: .75rem; }
        .never { margin-top: 1.5rem; }
        .never button { background: none; border: none; color: var(--muted); font-family: var(--body-font); font-size: .9rem; cursor: pointer; text-decoration: underline; padding: 0; }
        .never button:hover { color: var(--body); }
    </style>
</head>
<body>
    <div class="card">
        <div class="shield" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
            </svg>
        </div>

        <h1>Add an extra layer of security</h1>
        <p class="sub">
            Two-factor authentication protects your account even if your password
            is ever stolen. It takes about a minute to set up with an
            authenticator app.
        </p>

        <div class="actions">
            <a href="{{ route('dashboard.profile.edit') }}#two-factor" class="btn">Set up two-factor now</a>

            <form method="POST" action="{{ route('two-factor.recommend.later') }}">
                @csrf
                <button type="submit" class="btn btn-plain" style="width:100%;">Remind me next time</button>
            </form>
        </div>

        <p class="never">
            <form method="POST" action="{{ route('two-factor.recommend.never') }}">
                @csrf
                <button type="submit">Don't remind me again</button>
            </form>
        </p>
    </div>
</body>
</html>
