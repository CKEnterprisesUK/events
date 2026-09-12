{{--
    Shared public site header for the marketing site. Navy sticky bar with the
    "Events by CK Enterprises UK" wordmark (unchanged) and the primary nav.
    Used by layouts.app so every public page — homepage, Features, Pricing,
    How it works, For charities and the Trust Centre — shares one header.

    Primary destinations are dedicated pages (not homepage anchors), so the nav
    is a single source of truth: update it here and every public page follows.
    Auth state comes straight from Laravel's @auth/@else so we never duplicate
    login detection: logged-out visitors see Sign in + Get started; an
    authenticated organiser sees a single Dashboard link instead.

    Styling: `.pub-header*` / `.pub-nav*` / `.pub-mobile-*` in app.css.
--}}
@php($navLinks = [
    ['label' => 'Features', 'url' => route('features')],
    ['label' => 'Pricing', 'url' => route('pricing')],
    ['label' => 'How it works', 'url' => route('how-it-works')],
    ['label' => 'For charities', 'url' => route('for-charities')],
])
<header class="pub-header">
    <div class="pub-header__inner">
        <a class="pub-logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises UK">
            <span class="events">Events</span>
            <span class="by">by</span>
            <span class="ck">CK Enterprises UK</span>
        </a>

        <nav class="pub-nav" aria-label="Primary">
            @foreach ($navLinks as $link)
                <a class="pub-nav__link" href="{{ $link['url'] }}">{{ $link['label'] }}</a>
            @endforeach

            @auth
                <a class="pub-nav__btn" href="{{ route('dashboard.home') }}">Dashboard</a>
            @else
                <a class="pub-nav__link" href="{{ url('/login') }}">Sign in</a>
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

    <div class="pub-mobile-nav" id="pub-mobile-menu" hidden>
        <nav class="pub-mobile-nav__inner" aria-label="Mobile navigation">
            @foreach ($navLinks as $link)
                <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>
            @endforeach

            <div class="pub-mobile-nav__actions">
                @auth
                    <a class="pub-nav__btn" href="{{ route('dashboard.home') }}">Dashboard</a>
                @else
                    <a class="pub-nav__btn pub-nav__btn--secondary" href="{{ url('/login') }}">Sign in</a>
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

                function focusables() {
                    return menu.querySelectorAll('a[href], button:not([disabled])');
                }

                function openMenu() {
                    menu.hidden = false;
                    menu.classList.add('open');
                    toggle.setAttribute('aria-expanded', 'true');
                    toggle.setAttribute('aria-label', 'Close navigation');
                    document.body.classList.add('pub-menu-open');
                    var first = focusables()[0];
                    if (first) { first.focus(); }
                }

                function closeMenu(returnFocus) {
                    menu.classList.remove('open');
                    menu.hidden = true;
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.setAttribute('aria-label', 'Open navigation');
                    document.body.classList.remove('pub-menu-open');
                    if (returnFocus) { toggle.focus(); }
                }

                function isOpen() {
                    return menu.classList.contains('open');
                }

                toggle.addEventListener('click', function () {
                    if (isOpen()) { closeMenu(false); } else { openMenu(); }
                });

                // Follow a link: let navigation happen, but tidy the drawer state.
                menu.addEventListener('click', function (event) {
                    if (event.target.closest('a[href]')) { closeMenu(false); }
                });

                document.addEventListener('keydown', function (event) {
                    if (!isOpen()) { return; }

                    if (event.key === 'Escape') {
                        closeMenu(true);
                        return;
                    }

                    // Simple focus trap while the drawer is open.
                    if (event.key === 'Tab') {
                        var items = focusables();
                        if (!items.length) { return; }
                        var first = items[0];
                        var last = items[items.length - 1];
                        if (event.shiftKey && document.activeElement === first) {
                            event.preventDefault();
                            last.focus();
                        } else if (!event.shiftKey && document.activeElement === last) {
                            event.preventDefault();
                            first.focus();
                        }
                    }
                });

                // The drawer only exists below the desktop breakpoint; if the
                // viewport grows back to desktop, close it so focus/state reset.
                window.addEventListener('resize', function () {
                    if (window.innerWidth > 940 && isOpen()) { closeMenu(false); }
                });
            })();
        </script>
    @endpush
@endonce
