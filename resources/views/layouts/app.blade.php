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
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) ?: config('app.asset_version', '1') }}">
    @php $chrome = trim($__env->yieldContent('chrome', 'full')); @endphp
    @if ($chrome === 'minimal')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@700&display=swap" rel="stylesheet">
    @endif
    @stack('head')
</head>
<body>
    @if ($chrome !== 'minimal')
        <header class="public-header">
            <a class="brand" href="{{ url('/') }}">
                <img src="{{ asset('images/logo.png') }}" alt="{{ config('app.name', 'Event Ticketing Platform') }}">
            </a>
            <nav>
                @auth
                    <a href="{{ url('/dashboard') }}">Dashboard</a>
                    <form method="POST" action="{{ url('/logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-sm">Log out</button>
                    </form>
                @else
                    <a href="{{ url('/login') }}">Log in</a>
                    <a class="btn btn-sm" href="{{ url('/register') }}">Sign up</a>
                @endauth
            </nav>
        </header>
    @endif

    <main class="public-main @yield('main_class')">
        @yield('content')
    </main>

    @if ($chrome === 'minimal')
        <footer class="promo-footer">
            <div class="promo-footer__inner">
                <a class="promo-footer__logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises">
                    <span class="events">Events</span>
                    <span class="by">by</span>
                    <span class="ck">CK Enterprises</span>
                </a>
                <a class="promo-footer__cta" href="{{ url('/') }}">Sell your own tickets</a>
            </div>
        </footer>
    @else
        @include('layouts.partials.footer')
    @endif

    @stack('scripts')
</body>
</html>
