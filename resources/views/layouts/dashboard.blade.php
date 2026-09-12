<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') &middot; {{ config('app.name', 'Event Ticketing Platform') }}</title>
    @hasSection('favicon')
        @yield('favicon')
    @else
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/favicon.png') }}">
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) ?: config('app.asset_version', '1') }}">
    <style>
        /* "Events by CK Enterprises" wordmark in the dashboard header, matching
           the public/auth brand lockup on the dark header background. */
        .app-header .brand { text-decoration: none; }
        .app-header .brand .logo { display: inline-flex; align-items: baseline; gap: .35rem; font-family: 'Cabin Sketch', cursive; font-weight: 700; line-height: 1; }
        .app-header .brand .logo .events { font-size: 1.35rem; color: #2dd4bf; }
        .app-header .brand .logo .by { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; font-weight: 500; font-size: .7rem; letter-spacing: .05em; color: var(--dark-text-dim); text-transform: uppercase; }
        .app-header .brand .logo .ck { font-size: 1.35rem; color: #fff; }
        .app-header .header-user .profile-link { color: inherit; text-decoration: none; }
        .app-header .header-user .profile-link:hover strong { color: #fff; text-decoration: underline; }

        /* Compact user "chip" + dropdown menu. Keeps the header narrow: only an
           avatar, the name and a caret show inline; company/role/switcher live
           in the menu. */
        .app-header .header-user { position: relative; }
        .user-chip {
            display: inline-flex; align-items: center; gap: .55rem;
            background: transparent; border: 1px solid transparent; cursor: pointer;
            color: var(--dark-text); padding: .3rem .5rem; border-radius: .5rem; max-width: 220px;
        }
        .user-chip:hover, .user-chip[aria-expanded="true"] { background: var(--dark-2); border-color: var(--dark-border); }
        .user-chip .avatar {
            flex: 0 0 auto; width: 30px; height: 30px; border-radius: 999px;
            background: var(--brand); color: #fff; font-size: .78rem; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center; letter-spacing: .02em;
        }
        .user-chip .chip-text { display: flex; flex-direction: column; align-items: flex-start; line-height: 1.15; min-width: 0; }
        .user-chip .chip-name { color: #fff; font-weight: 600; font-size: .85rem; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .user-chip .chip-sub { color: var(--dark-text-dim); font-size: .72rem; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .user-chip .chip-caret { color: var(--dark-text-dim); font-size: .7rem; margin-left: .15rem; }

        .user-menu {
            position: absolute; top: calc(100% + .5rem); right: 0; z-index: 60;
            width: 280px; max-height: 70vh; overflow-y: auto;
            background: #fff; color: #1e293b; border: 1px solid #e2e8f0;
            border-radius: .6rem; box-shadow: 0 12px 32px rgba(15,23,42,.18); padding: .4rem;
        }
        .user-menu[hidden] { display: none; }
        .user-menu .menu-head { padding: .55rem .6rem .5rem; border-bottom: 1px solid #eef2f7; margin-bottom: .35rem; }
        .user-menu .menu-name { display: block; font-weight: 700; font-size: .9rem; }
        .user-menu .menu-email { display: block; color: #64748b; font-size: .78rem; margin-bottom: .4rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .user-menu .role-badge { display: inline-block; background: #eef2ff; color: var(--brand); border: 1px solid #dbe1ff; padding: .1rem .5rem; border-radius: 999px; font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
        .user-menu .menu-section { padding: .15rem 0; }
        .user-menu .menu-label { margin: .3rem .6rem .25rem; font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; font-weight: 600; }
        .user-menu .menu-item {
            display: flex; flex-direction: column; align-items: flex-start; width: 100%; text-align: left;
            padding: .5rem .6rem; border-radius: .4rem; color: #1e293b; text-decoration: none;
            background: transparent; border: 0; cursor: pointer; font-size: .85rem;
        }
        .user-menu .menu-item:hover { background: #f1f5f9; }
        .user-menu .menu-item .menu-item-name { font-weight: 600; }
        .user-menu .menu-item .menu-item-meta { color: #94a3b8; font-size: .74rem; }
        .user-menu .menu-item-danger { color: #b91c1c; font-weight: 600; }
        .user-menu .menu-item-danger:hover { background: #fef2f2; }
        .user-menu .menu-divider { height: 1px; background: #eef2f7; margin: .35rem 0; }
        .user-menu .menu-empty { padding: .4rem .6rem; color: #94a3b8; font-size: .82rem; }
        .user-menu .menu-current { padding: .45rem .6rem; background: #f0fdfa; border: 1px solid #ccfbf1; border-radius: .4rem; margin: 0 .1rem .35rem; }
        .user-menu .menu-current-name { display: block; font-weight: 700; font-size: .85rem; }
        .user-menu .menu-current-tag { display: block; color: #0d9488; font-size: .72rem; }
        .user-menu form { margin: 0; }

        /* Slim banner shown while a super admin is acting inside a company. */
        .impersonation-banner {
            display: flex; align-items: center; gap: .55rem; flex-wrap: wrap;
            background: #fffbeb; color: #92400e; border-bottom: 1px solid #fde68a;
            padding: .5rem 1.5rem; font-size: .85rem;
        }
        .impersonation-banner strong { color: #78350f; }
        .impersonation-banner .imp-dot { width: .55rem; height: .55rem; border-radius: 999px; background: #f59e0b; display: inline-block; }
        .impersonation-banner .imp-exit { margin-left: auto; }
        .impersonation-banner .imp-exit button {
            background: #92400e; color: #fff; border: 0; cursor: pointer;
            padding: .3rem .7rem; border-radius: .35rem; font-size: .78rem; font-weight: 600;
        }
        .impersonation-banner .imp-exit button:hover { background: #78350f; }

        @media (max-width: 860px) {
            .user-chip .chip-text { display: none; }
            .user-chip .chip-caret { display: none; }
        }

        /* Pin the Help & support nav section to the bottom of the sidebar.
           The nav fills the sidebar height and the Support heading is pushed
           down with an auto top margin, so Help sits in the bottom-left as a
           persistent, always-available entry point. On short/scrolling nav
           lists it simply follows the last item. */
        .app-sidebar nav[aria-label="Primary"] {
            display: flex;
            flex-direction: column;
            min-height: 100%;
        }
        .app-sidebar .nav-section-help { margin-top: auto; }
    </style>
    @stack('head')
</head>
<body>
@php
    $user = auth()->user();
    $isSuperAdmin = (bool) $user?->isSuperAdmin();

    // The Company the current request is acting on. For a Super_Admin this is
    // the Company they have "jumped into" (session flag); for a Company_User it
    // is their own Company.
    $impersonatedCompanyId = $isSuperAdmin
        ? session(\App\Http\Controllers\SuperAdmin\ImpersonationController::SESSION_KEY)
        : null;

    if ($isSuperAdmin) {
        $company = $impersonatedCompanyId ? \App\Models\Company::find($impersonatedCompanyId) : null;
        // Companies a Super_Admin can jump into (exclude suspended ones).
        $switchableCompanies = \App\Models\Company::query()
            ->where('status', '!=', \App\Models\Company::STATUS_SUSPENDED)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    } else {
        $company = $user?->company;
        $switchableCompanies = collect();
    }

    $impersonating = $isSuperAdmin && $company !== null;

    // Helper: is the current route one of the given names/prefixes?
    $navActive = fn (string ...$patterns) => collect($patterns)
        ->contains(fn ($p) => request()->routeIs($p));
@endphp
<div class="app-shell" id="appShell">
    <header class="app-header">
        <button type="button" class="nav-toggle" id="navToggle" aria-label="Open navigation menu" aria-controls="appSidebar" aria-expanded="false">
            <x-icon name="menu" aria-hidden="true" />
        </button>

        <a href="{{ $isSuperAdmin && ! $impersonating ? url('/admin') : url('/dashboard') }}" class="brand" aria-label="Events by CK Enterprises">
            <span class="logo">
                <span class="events">Events</span>
                <span class="by">by</span>
                <span class="ck">CK Enterprises</span>
            </span>
        </a>

        {{-- Mobile-only current context label. Shows the event name inside an
             event, otherwise the current page title, so a user always knows
             where they are without the sidebar visible. --}}
        @php
            $mobileContext = (isset($event) && $event instanceof \App\Models\Event)
                ? $event->name
                : trim($__env->yieldContent('page_title', ''));
        @endphp
        @if ($mobileContext !== '')
            <span class="app-header__context" aria-hidden="true">{{ $mobileContext }}</span>
        @endif

        <div class="header-spacer"></div>

        @php
            // Compact initials for the avatar chip.
            $initials = collect(preg_split('/\s+/', trim((string) $user?->name)))
                ->filter()
                ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
                ->take(2)
                ->implode('');
            $initials = $initials !== '' ? $initials : '?';
        @endphp

        <div class="header-user" id="headerUser">
            <button type="button" class="user-chip" id="userChipToggle" aria-haspopup="true" aria-expanded="false" aria-controls="userMenu">
                <span class="avatar" aria-hidden="true">{{ $initials }}</span>
                <span class="chip-text">
                    <span class="chip-name">{{ $user?->name }}</span>
                    <span class="chip-sub">
                        @if ($impersonating)
                            {{ $company->name }}
                        @elseif ($isSuperAdmin)
                            Super Admin
                        @elseif ($company)
                            {{ $company->name }}
                        @endif
                    </span>
                </span>
                <span class="chip-caret" aria-hidden="true">&#9662;</span>
            </button>

            <div class="user-menu" id="userMenu" role="menu" aria-labelledby="userChipToggle" hidden>
                <div class="menu-head">
                    <span class="menu-name">{{ $user?->name }}</span>
                    <span class="menu-email">{{ $user?->email }}</span>
                    @if ($isSuperAdmin)
                        <span class="role-badge">Super Admin</span>
                    @elseif ($user?->role)
                        <span class="role-badge">{{ ucfirst($user->role) }}</span>
                    @endif
                </div>

                @if ($isSuperAdmin)
                    <div class="menu-section">
                        <p class="menu-label">Jump into a company</p>
                        @if ($impersonating)
                            <div class="menu-current">
                                <span class="menu-current-name">{{ $company->name }}</span>
                                <span class="menu-current-tag">Acting as admin</span>
                            </div>
                            <form method="POST" action="{{ route('admin.impersonate.stop') }}">
                                @csrf
                                <button type="submit" class="menu-item menu-item-button">&#8592; Back to platform</button>
                            </form>
                            <div class="menu-divider"></div>
                        @endif

                        @forelse ($switchableCompanies as $switchable)
                            @if (! $impersonating || $switchable->id !== $company->id)
                                <form method="POST" action="{{ route('admin.impersonate.start', $switchable) }}">
                                    @csrf
                                    <button type="submit" class="menu-item menu-item-button">
                                        <span class="menu-item-name">{{ $switchable->name }}</span>
                                        <span class="menu-item-meta">/{{ $switchable->slug }}</span>
                                    </button>
                                </form>
                            @endif
                        @empty
                            <p class="menu-empty">No active companies</p>
                        @endforelse
                    </div>
                    <div class="menu-divider"></div>
                @endif

                @if (! $isSuperAdmin)
                    <a href="{{ route('dashboard.profile.edit') }}" class="menu-item" role="menuitem">Your profile</a>
                    <a href="{{ route('dashboard.profile.edit') }}#two-factor" class="menu-item" role="menuitem">Two-factor authentication</a>
                    <div class="menu-divider"></div>
                @endif

                <form method="POST" action="{{ url('/logout') }}">
                    @csrf
                    <button type="submit" class="menu-item menu-item-button menu-item-danger" role="menuitem">Log out</button>
                </form>
            </div>
        </div>
    </header>

    @if ($impersonating)
        <div class="impersonation-banner" role="status">
            <span class="imp-dot" aria-hidden="true"></span>
            Viewing <strong>{{ $company->name }}</strong> as a super admin.
            <form method="POST" action="{{ route('admin.impersonate.stop') }}" class="imp-exit">
                @csrf
                <button type="submit">Exit to platform</button>
            </form>
        </div>
    @endif

    <div class="app-body">
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <aside class="app-sidebar" id="appSidebar">
            {{-- Desktop-only collapse control for the Main_Sidebar. Toggles
                 `app-shell--rail` on `#appShell` to switch to an icons-only rail;
                 wired up + persisted (localStorage key ck.sidebar.main) by the
                 bindCollapse JS in task 9.3. This is SEPARATE from the mobile
                 hamburger (#navToggle / `nav-open` drawer), which is unchanged. --}}
            <div class="app-sidebar__head">
                <button type="button"
                        id="mainNavToggle"
                        class="app-sidebar__toggle"
                        aria-expanded="true"
                        aria-controls="appShell"
                        aria-label="Collapse navigation">
                    <x-icon name="panel-left" />
                </button>
            </div>
            @php
                // Event context: layouts/event.blade.php (and other per-event
                // screens) pass an $event into the view, and set the
                // `event_nav` section so the main sidebar swaps its
                // organisation nav for the event-context nav. This is the single
                // navigation system while inside an event — there is never a
                // second floating event menu.
                $eventNav = isset($event) && $event instanceof \App\Models\Event
                    && trim($__env->yieldContent('event_nav', '')) === '1';
                $activeSection = trim($__env->yieldContent('active_section', 'overview'));
            @endphp
            <nav aria-label="{{ $eventNav ? 'Event' : 'Primary' }}" class="{{ $eventNav ? 'nav--event' : '' }}">
                @if ($eventNav)
                    @include('layouts.partials.sidebar-event-nav', [
                        'event' => $event,
                        'readiness' => $readiness ?? null,
                        'activeSection' => $activeSection,
                        'company' => $company,
                        'navActive' => $navActive,
                    ])
                @else
                    @include('layouts.partials.sidebar-org-nav', [
                        'isSuperAdmin' => $isSuperAdmin,
                        'impersonating' => $impersonating,
                        'company' => $company,
                        'navActive' => $navActive,
                    ])
                @endif
            </nav>
        </aside>

        <div class="app-content">
            <main class="dashboard-main">
                @hasSection('page_title')
                    <div class="page-head">
                        <h1>@yield('page_title')</h1>
                        @yield('page_actions')
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
</div>

<script>
    // Generic collapse toggle with persistence (Req 2.3–2.7). Toggles a rail
    // class on the target, persists "rail"|"open" under storeKey, keeps
    // aria-expanded + aria-label in sync, and nudges a Leaflet map (exposed as
    // window.__eventMap by _location.blade.php) to recompute its size once the
    // rail width has settled. Returns early when the button/target aren't on
    // the page, so it's safe to call for the section nav on every dashboard
    // page even though it only exists on the manage screen.
    function bindCollapse(btnId, targetId, cls, storeKey) {
        var btn = document.getElementById(btnId), target = document.getElementById(targetId);
        if (!btn || !target) return;
        if (localStorage.getItem(storeKey) === 'rail') target.classList.add(cls);
        var sync = function () {
            var railed = target.classList.contains(cls);
            btn.setAttribute('aria-expanded', railed ? 'false' : 'true');
            btn.setAttribute('aria-label', railed ? 'Expand navigation' : 'Collapse navigation');
        };
        sync();
        btn.addEventListener('click', function () {
            var railed = target.classList.toggle(cls);
            localStorage.setItem(storeKey, railed ? 'rail' : 'open');
            sync();
            if (window.__eventMap) setTimeout(function () { window.__eventMap.invalidateSize(); }, 200);
        });
    }
    // Main_Sidebar desktop rail collapse (every dashboard page). The old
    // per-event Section_Nav rail is gone — event navigation now lives in this
    // same sidebar — so only the main toggle is wired.
    bindCollapse('mainNavToggle', 'appShell', 'app-shell--rail', 'ck.sidebar.main');

    (function () {
        var shell = document.getElementById('appShell');
        var toggle = document.getElementById('navToggle');
        var backdrop = document.getElementById('sidebarBackdrop');
        var sidebar = document.getElementById('appSidebar');
        if (!shell || !toggle || !sidebar) return;

        var isOpen = false;

        function focusable() {
            return Array.prototype.slice.call(sidebar.querySelectorAll(
                'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])'
            )).filter(function (el) { return el.offsetParent !== null; });
        }

        function open() {
            isOpen = true;
            shell.classList.add('nav-open');
            toggle.setAttribute('aria-expanded', 'true');
            document.body.classList.add('nav-locked');
            var f = focusable();
            if (f.length) f[0].focus();
        }
        function close(returnFocus) {
            isOpen = false;
            shell.classList.remove('nav-open');
            toggle.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('nav-locked');
            if (returnFocus) toggle.focus();
        }
        toggle.addEventListener('click', function () {
            if (isOpen) { close(true); } else { open(); }
        });
        if (backdrop) backdrop.addEventListener('click', function () { close(false); });

        // Close the drawer when a navigation link is followed (route change).
        sidebar.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function () { close(false); });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) { close(true); return; }
            // Focus trap: only while the drawer is the modal surface (mobile,
            // where the hamburger is visible). On desktop it's a static column.
            if (e.key !== 'Tab' || !isOpen) return;
            if (getComputedStyle(toggle).display === 'none') return;
            var f = focusable();
            if (!f.length) return;
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault(); last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault(); first.focus();
            }
        });
    })();

    // User chip dropdown menu.
    (function () {
        var toggle = document.getElementById('userChipToggle');
        var menu = document.getElementById('userMenu');
        if (!toggle || !menu) return;

        function open() {
            menu.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
        }
        function closeMenu() {
            menu.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (menu.hidden) { open(); } else { closeMenu(); }
        });
        // Keep clicks inside the menu (e.g. before a form submits) from closing it.
        menu.addEventListener('click', function (e) { e.stopPropagation(); });
        document.addEventListener('click', closeMenu);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
    })();
</script>
@stack('scripts')
</body>
</html>
