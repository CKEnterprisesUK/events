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
    @stack('head')
</head>
<body>
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

    <main class="public-main @yield('main_class')">
        @yield('content')
    </main>

    @include('layouts.partials.footer')

    @stack('scripts')
</body>
</html>
