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
    {{-- The public chrome (header/footer wordmark) uses the Cabin Sketch
         wordmark font and the Poppins/Inter type used on the landing page, so
         load them for both the full public chrome and the minimal variant. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@700&family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    @stack('head')
</head>
<body>
    @if ($chrome !== 'minimal')
        @include('layouts.partials.public-header')
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
        @include('layouts.partials.public-footer')
    @endif

    @stack('scripts')
</body>
</html>
