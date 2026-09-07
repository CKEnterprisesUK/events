{{--
    Shared public site header, matching the landing page chrome. Navy sticky
    bar with the "Events by CK Enterprises UK" wordmark and primary nav. Used by
    layouts.app so public pages (Trust & Legal Centre, privacy, auth) share the
    same header as the main landing page. Styling: `.pub-header*` in app.css.

    The primary section links (Why us / Pricing / How it works) live on the
    landing page, so they point at the homepage anchors. This keeps the nav
    identical to the landing page rather than a squished cut-down version.
--}}
<header class="pub-header">
    <div class="pub-header__inner">
        <a class="pub-logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises UK">
            <span class="events">Events</span>
            <span class="by">by</span>
            <span class="ck">CK Enterprises UK</span>
        </a>

        <nav class="pub-nav" aria-label="Primary">
            <a class="pub-nav__link" href="{{ url('/#why') }}">Why us</a>
            <a class="pub-nav__link" href="{{ url('/#pricing') }}">Pricing</a>
            <a class="pub-nav__link" href="{{ url('/#how') }}">How it works</a>
            <a class="pub-nav__link" href="{{ route('trust.index') }}">Trust &amp; Legal</a>

            @auth
                <a class="pub-nav__btn" href="{{ route('dashboard.home') }}">Dashboard</a>
                <form method="POST" action="{{ url('/logout') }}">
                    @csrf
                    <button type="submit" class="pub-nav__link pub-nav__logout">Log out</button>
                </form>
            @else
                <a class="pub-nav__link" href="{{ url('/login') }}">Log in</a>
                <a class="pub-nav__btn" href="{{ url('/register') }}">Get started</a>
            @endauth
        </nav>

        <button
            class="pub-mobile-toggle"
            id="pub-menu-toggle"
            type="button"
            aria-expanded="false"
            aria-controls="pub-mobile-menu"
            aria-label="Open navigation"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M4 7h16M4 12h16M4 17h16"/>
            </svg>
        </button>
    </div>

    <div class="pub-mobile-nav" id="pub-mobile-menu">
        <nav class="pub-mobile-nav__inner" aria-label="Mobile navigation">
            <a href="{{ url('/#why') }}">Why us</a>
            <a href="{{ url('/#pricing') }}">Pricing</a>
            <a href="{{ url('/#how') }}">How it works</a>
            <a href="{{ route('trust.index') }}">Trust &amp; Legal</a>

            <div class="pub-mobile-nav__actions">
                @auth
                    <a class="pub-nav__btn" href="{{ route('dashboard.home') }}">Dashboard</a>
                    <form method="POST" action="{{ url('/logout') }}">
                        @csrf
                        <button type="submit" class="pub-nav__btn pub-nav__btn--secondary">Log out</button>
                    </form>
                @else
                    <a class="pub-nav__btn pub-nav__btn--secondary" href="{{ url('/login') }}">Log in</a>
                    <a class="pub-nav__btn" href="{{ url('/register') }}">Get started</a>
                @endauth
            </div>
        </nav>
    </div>
</header>

@once
    @push('scripts')
        <script>
            (function () {
                var toggle = document.getElementById('pub-menu-toggle');
                var menu = document.getElementById('pub-mobile-menu');
                if (!toggle || !menu) return;

                toggle.addEventListener('click', function () {
                    var open = menu.classList.toggle('open');
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
                });
            })();
        </script>
    @endpush
@endonce
