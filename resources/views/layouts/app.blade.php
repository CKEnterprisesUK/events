<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'Event Ticketing Platform'))</title>
    <style>
        :root {
            --brand: #2563eb;
            --brand-dark: #1d4ed8;
            --ink: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #1f2937;
            background: #f9fafb;
            line-height: 1.5;
        }
        a { color: var(--brand); }
        h1 { font-size: 1.875rem; line-height: 1.2; margin: 0 0 0.75rem; color: var(--ink); }
        header.site-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.5rem;
            background: #fff;
            border-bottom: 1px solid var(--border);
        }
        header.site-header a.brand { font-weight: 700; color: var(--brand); text-decoration: none; font-size: 1.125rem; }
        header.site-header nav { display: flex; align-items: center; gap: 1rem; }
        header.site-header nav a { color: #374151; text-decoration: none; font-weight: 500; }
        header.site-header nav a:hover { color: var(--ink); }
        main.site-main { max-width: 960px; margin: 0 auto; padding: 2rem 1.5rem; }
        footer.site-footer { text-align: center; padding: 2rem 1.5rem; color: var(--muted); font-size: 0.875rem; }
        .muted { color: var(--muted); }

        .btn {
            display: inline-block; padding: 0.625rem 1.25rem; border-radius: 0.5rem;
            background: var(--brand); color: #fff; text-decoration: none; border: none; cursor: pointer;
            font-size: 1rem; font-weight: 600; transition: background 0.15s ease;
        }
        .btn:hover { background: var(--brand-dark); color: #fff; }
        .btn-outline { background: transparent; color: var(--brand); box-shadow: inset 0 0 0 1px var(--border); }
        .btn-outline:hover { background: #f3f4f6; color: var(--brand-dark); }
        .btn-block { width: 100%; }

        .field { margin-bottom: 1rem; }
        .field label { display: block; margin-bottom: 0.25rem; font-weight: 600; }
        .field input { width: 100%; padding: 0.5rem 0.625rem; border: 1px solid #d1d5db; border-radius: 0.5rem; font-size: 1rem; }
        .field input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); }
        .hint { font-size: 0.8125rem; color: var(--muted); margin: 0.25rem 0 0; }
        .error { color: #b91c1c; font-size: 0.875rem; margin-top: 0.25rem; }

        /* Auth cards (login / signup) */
        .auth-card {
            max-width: 460px; margin: 1rem auto; background: #fff;
            border: 1px solid var(--border); border-radius: 0.75rem;
            padding: 2rem; box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .auth-card .muted { margin-top: 0; }
        .auth-alt { text-align: center; margin-top: 1.25rem; font-size: 0.9375rem; color: var(--muted); }
        .divider { border: none; border-top: 1px solid var(--border); margin: 1.5rem 0; }
        .slug-input { display: flex; align-items: stretch; }
        .slug-input .slug-prefix {
            display: inline-flex; align-items: center; padding: 0 0.625rem;
            background: #f3f4f6; border: 1px solid #d1d5db; border-right: none;
            border-radius: 0.5rem 0 0 0.5rem; color: var(--muted); font-size: 0.875rem; white-space: nowrap;
        }
        .slug-input input { border-radius: 0 0.5rem 0.5rem 0; }

        /* Landing hero */
        .hero { text-align: center; padding: 3rem 0 2.5rem; }
        .hero h1 { font-size: 2.5rem; max-width: 720px; margin: 0 auto 1rem; }
        .hero p.lead { font-size: 1.125rem; color: var(--muted); max-width: 620px; margin: 0 auto 1.75rem; }
        .hero .cta { display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
        .features { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin: 2rem 0; }
        .feature-card {
            background: #fff; border: 1px solid var(--border); border-radius: 0.75rem; padding: 1.5rem;
        }
        .feature-card h3 { margin: 0 0 0.5rem; font-size: 1.0625rem; color: var(--ink); }
        .feature-card p { margin: 0; color: var(--muted); font-size: 0.9375rem; }
    </style>
    @stack('head')
</head>
<body>
    <header class="site-header">
        <a class="brand" href="{{ url('/') }}">{{ config('app.name', 'Event Ticketing Platform') }}</a>
        <nav>
            @auth
                <form method="POST" action="{{ url('/logout') }}" style="display:inline">
                    @csrf
                    <button type="submit" class="btn">Log out</button>
                </form>
            @else
                <a href="{{ url('/login') }}">Log in</a>
                <a class="btn" href="{{ url('/register') }}">Sign up</a>
            @endauth
        </nav>
    </header>

    <main class="site-main">
        @yield('content')
    </main>

    <footer class="site-footer">
        &copy; {{ date('Y') }} CK Enterprises. All rights reserved.
    </footer>

    @stack('scripts')
</body>
</html>
