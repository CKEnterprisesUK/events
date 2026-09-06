<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'Event Ticketing Platform'))</title>
    @hasSection('favicon')
        @yield('favicon')
    @else
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/favicon.png') }}">
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* --brand is overridden per-company via @@yield('brand-style') */
            --brand: #674df3;
            --ink: #1b1f2e;
            --body: #454a5a;
            --muted: #838694;
            --line: #e6e7ec;
            --surface: #ffffff;
            --surface-2: #f6f7f9;
            --font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            display: flex; flex-direction: column;
            font-family: var(--font);
            color: var(--body);
            background: var(--surface-2);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3 { color: var(--ink); line-height: 1.25; margin: 0; }
        a { color: var(--brand); text-decoration: none; }
        img { max-width: 100%; }

        .store-shell { flex: 1; width: 100%; max-width: 860px; margin: 0 auto; padding: 0 1.25rem; }

        /* Organiser branded header */
        .store-header {
            text-align: center;
            padding: 3rem 1.25rem 2rem;
        }
        .store-header .store-logo { max-height: 96px; width: auto; margin: 0 auto 1.25rem; display: block; }
        .store-header h1 { font-size: 2rem; color: var(--ink); }

        /* Content card */
        .store-body { padding-bottom: 3rem; }
        .store-section h2 { font-size: 1.15rem; margin-bottom: 1rem; color: var(--ink); }
        .empty {
            background: var(--surface); border: 1px dashed var(--line); border-radius: 10px;
            padding: 2.5rem 1.5rem; text-align: center; color: var(--muted); margin: 0;
        }
        .event-list { list-style: none; padding: 0; margin: 0; display: grid; gap: .85rem; }
        .event-list-item {
            background: var(--surface); border: 1px solid var(--line); border-radius: 10px;
            padding: 1.15rem 1.35rem; transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }
        .event-list-item:hover { border-color: var(--brand); box-shadow: 0 8px 24px rgba(27,31,46,.08); transform: translateY(-2px); }
        .event-list-item > a { font-size: 1.15rem; font-weight: 600; color: var(--ink); display: inline-block; }
        .event-list-item:hover > a { color: var(--brand); }
        .event-list-item .event-venue,
        .event-list-item .event-date { display: block; font-size: .92rem; color: var(--muted); margin-top: .25rem; }

        /* Subtle powered-by credit — advertises us without taking over */
        .store-powered {
            border-top: 1px solid var(--line);
            padding: 1.5rem 1.25rem 2rem;
            text-align: center;
            font-size: .85rem;
            color: var(--muted);
        }
        .store-powered a { color: var(--muted); font-weight: 600; }
        .store-powered a:hover { color: var(--ink); }

        @media (max-width: 520px) {
            .store-header { padding: 2rem 1rem 1.5rem; }
            .store-header h1 { font-size: 1.6rem; }
        }
    </style>
    @yield('brand-style')
    @stack('head')
</head>
<body>
    <div class="store-shell">
        @yield('content')
    </div>

    <div class="store-powered">
        Powered by <a href="{{ url('/') }}">Events by CK Enterprises UK</a>
    </div>

    @stack('scripts')
</body>
</html>
