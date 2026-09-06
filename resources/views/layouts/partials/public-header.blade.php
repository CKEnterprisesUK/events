{{--
    Shared public site header, matching the landing page chrome. Navy sticky
    bar with the "Events by CK Enterprises" wordmark and primary nav. Used by
    layouts.app so public pages (Trust & Legal Centre, privacy, auth) share the
    same header as the main landing page. Styling: `.pub-header*` in app.css.
--}}
<header class="pub-header">
    <div class="pub-header__inner">
        <a class="pub-logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises">
            <span class="events">Events</span>
            <span class="by">by</span>
            <span class="ck">CK Enterprises</span>
        </a>
        <nav class="pub-nav" aria-label="Primary">
            @auth
                <a class="pub-nav__link" href="{{ route('dashboard.home') }}">Dashboard</a>
                <form method="POST" action="{{ url('/logout') }}">
                    @csrf
                    <button type="submit" class="pub-nav__btn">Log out</button>
                </form>
            @else
                <a class="pub-nav__link" href="{{ url('/login') }}">Log in</a>
                <a class="pub-nav__btn" href="{{ url('/register') }}">Get started</a>
            @endauth
        </nav>
    </div>
</header>
