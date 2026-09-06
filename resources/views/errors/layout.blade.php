<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') &middot; {{ config('app.name', 'Event Ticketing') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f1425;
            --navy-2: #1b1f2e;
            --purple: #674df3;
            --purple-dark: #5238d6;
            --teal: #30f0b6;
            --muted: #9aa0b5;
            --heading-font: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --body-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            font-family: var(--body-font);
            background: var(--navy);
            color: #c1c5d4;
            line-height: 1.6;
            display: flex; flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--purple); text-decoration: none; }

        .top { padding: 1.75rem 2rem; }
        .logo { display: inline-flex; align-items: center; gap: .6rem; font-family: var(--heading-font); font-weight: 700; font-size: 1.15rem; color: #fff; }
        .logo .mark { width: 32px; height: 32px; border-radius: 7px; background: var(--purple); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: .85rem; }

        .wrap { flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem; text-align: center; }
        .inner { max-width: 520px; }

        .code {
            font-family: var(--heading-font); font-weight: 800; line-height: 1;
            font-size: clamp(6rem, 22vw, 11rem);
            background: linear-gradient(180deg, #fff 0%, #4a5170 130%);
            -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
            margin: 0 0 .5rem;
        }
        h1 { font-family: var(--heading-font); color: #fff; font-size: 1.7rem; margin: 0 0 .75rem; }
        p.msg { font-size: 1.08rem; color: var(--muted); margin: 0 auto 2rem; max-width: 420px; }

        .actions { display: flex; gap: .85rem; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            padding: .8rem 1.6rem; border-radius: 6px; font-weight: 600; font-size: 1rem;
            font-family: var(--body-font); cursor: pointer; border: 1px solid transparent;
            transition: background .15s ease, color .15s ease, border-color .15s ease;
        }
        .btn-primary { background: var(--purple); color: #fff; }
        .btn-primary:hover { background: var(--purple-dark); color: #fff; }
        .btn-ghost { background: transparent; color: #fff; border-color: rgba(255,255,255,.35); }
        .btn-ghost:hover { border-color: #fff; }

        .foot { padding: 1.75rem 2rem; text-align: center; font-size: .85rem; color: #71768c; }
        .foot a { color: #c1c5d4; }
    </style>
</head>
<body>
    <header class="top">
        <a class="logo" href="{{ url('/') }}">
            <span class="mark">CK</span>
            <span>{{ config('app.name', 'Event Ticketing') }}</span>
        </a>
    </header>

    <main class="wrap">
        <div class="inner">
            <div class="code">@yield('code')</div>
            <h1>@yield('heading')</h1>
            <p class="msg">@yield('message')</p>
            <div class="actions">
                @yield('actions')
            </div>
        </div>
    </main>

    <footer class="foot">
        A <a href="https://ckenterprises.co.uk/" target="_blank" rel="noopener">CK Enterprises UK</a> product
    </footer>
</body>
</html>
