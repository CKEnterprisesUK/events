<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name', 'Event Ticketing Platform'))</title>
    <style>
        :root { --brand: #2563eb; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #1f2937;
            background: #f9fafb;
            line-height: 1.5;
        }
        header.site-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.5rem;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
        }
        header.site-header a.brand { font-weight: 700; color: var(--brand); text-decoration: none; }
        header.site-header nav a { margin-left: 1rem; color: #374151; text-decoration: none; }
        main.site-main { max-width: 960px; margin: 0 auto; padding: 2rem 1.5rem; }
        footer.site-footer { text-align: center; padding: 2rem 1.5rem; color: #6b7280; font-size: 0.875rem; }
        .btn {
            display: inline-block; padding: 0.5rem 1rem; border-radius: 0.375rem;
            background: var(--brand); color: #fff; text-decoration: none; border: none; cursor: pointer;
            font-size: 1rem;
        }
        .field { margin-bottom: 1rem; }
        .field label { display: block; margin-bottom: 0.25rem; font-weight: 600; }
        .field input { width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 0.375rem; }
        .error { color: #b91c1c; font-size: 0.875rem; margin-top: 0.25rem; }
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
