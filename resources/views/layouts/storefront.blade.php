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
    <link href="https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* --brand is overridden per-company via @@yield('brand-style') */
            --brand: #674df3;
            --navy: #0f1425;
            --purple: #674df3;
            --purple-dark: #5238d6;
            --teal: #30f0b6;
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
            /* Soft branded wash at the top so the default isn't flat white; a
               branded store tints this with its own primary colour. */
            background:
                radial-gradient(1000px 380px at 50% -120px, color-mix(in srgb, var(--brand) 16%, transparent), transparent 70%),
                var(--surface-2);
            background-repeat: no-repeat;
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
        .store-header__inner { position: relative; }
        .store-header .store-logo { max-height: 96px; width: auto; margin: 0 auto 1.25rem; display: block; }
        .store-header h1 { font-size: 2rem; color: var(--ink); }

        /* Poster / hero image atop a branded storefront. The header content
           overlaps the base of the hero so the logo sits on the imagery. */
        .store-header--poster {
            padding-top: 0;
            /* let the hero break out of the centred shell to full width */
            margin-left: calc(50% - 50vw);
            margin-right: calc(50% - 50vw);
            width: 100vw;
        }
        .store-header--poster .store-header__inner {
            max-width: 860px; margin: -3.25rem auto 0; padding: 0 1.25rem;
        }
        .store-hero { position: relative; width: 100%; aspect-ratio: 3 / 1; max-height: 320px; overflow: hidden; }
        .store-hero__img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .store-hero__overlay {
            position: absolute; inset: 0;
            background: linear-gradient(to bottom, rgba(15,20,37,0) 40%, color-mix(in srgb, var(--surface-2) 92%, transparent) 100%);
        }
        .store-header--poster .store-logo {
            position: relative; background: var(--surface);
            padding: .6rem .9rem; border-radius: 14px;
            box-shadow: 0 10px 30px rgba(15,20,37,.18); max-height: 108px;
        }

        /* Content card */
        .store-body { padding-bottom: 3rem; }
        .store-section { margin-top: 2.5rem; }
        .store-section:first-child { margin-top: 0; }
        .store-section h2 { font-size: 1.15rem; margin-bottom: 1rem; color: var(--ink); }

        /* About-the-company blurb */
        .store-about__text {
            background: var(--surface); border: 1px solid var(--line); border-radius: 14px;
            padding: 1.35rem 1.5rem; margin: 0; color: var(--body);
        }

        /* Sponsor banners */
        .store-sponsors__grid {
            display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;
        }
        .store-sponsors__img {
            max-width: 100%; max-height: 90px; width: auto; height: auto;
            background: var(--surface); border: 1px solid var(--line);
            border-radius: 12px; padding: .6rem 1rem;
        }

        /* Social / website + legal links */
        .store-links { display: flex; flex-direction: column; gap: .9rem; }
        .store-links__list, .store-links__legal {
            list-style: none; padding: 0; margin: 0;
            display: flex; flex-wrap: wrap; gap: .6rem .9rem; align-items: center;
        }
        .store-links__list a {
            display: inline-flex; align-items: center;
            padding: .45rem .9rem; border-radius: 999px;
            border: 1px solid var(--line); background: var(--surface);
            font-size: .9rem; font-weight: 600; color: var(--ink);
            transition: border-color .15s ease, color .15s ease;
        }
        .store-links__list a:hover { border-color: var(--brand); color: var(--brand); }
        .store-links__legal { font-size: .85rem; }
        .store-links__legal a { color: var(--muted); }
        .store-links__legal a:hover { color: var(--brand); }
        .empty {
            background: var(--surface); border: 1px dashed var(--line); border-radius: 10px;
            padding: 2.5rem 1.5rem; text-align: center; color: var(--muted); margin: 0;
        }
        .event-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 1rem; }
        .event-list-item {
            background: var(--surface); border: 1px solid var(--line); border-radius: 14px;
            overflow: hidden; transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
        }
        .event-list-item:hover { border-color: var(--brand); box-shadow: 0 12px 30px rgba(27,31,46,.1); transform: translateY(-2px); }
        .event-card { display: flex; align-items: stretch; gap: 0; color: inherit; }
        .event-card__thumb { flex: 0 0 34%; max-width: 220px; background: var(--surface-2); }
        .event-card__thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .event-card__body { display: flex; flex-direction: column; gap: .3rem; padding: 1.15rem 1.35rem; flex: 1; }
        .event-card__title { font-size: 1.2rem; font-weight: 600; color: var(--ink); }
        .event-list-item:hover .event-card__title { color: var(--brand); }
        .event-card .event-venue,
        .event-card .event-date { font-size: .92rem; color: var(--muted); }
        .event-card__cta {
            margin-top: .5rem; font-size: .9rem; font-weight: 600; color: var(--brand);
            display: inline-flex; align-items: center; gap: .3rem;
        }

        @media (max-width: 560px) {
            .event-card { flex-direction: column; }
            .event-card__thumb { flex-basis: auto; max-width: none; aspect-ratio: 16 / 9; }
        }

        /* Storefront footer — advertises us without taking over the store */
        .store-footer { margin-top: auto; background: var(--navy); color: #c1c5d4; }
        .store-footer__inner {
            max-width: 860px; margin: 0 auto; padding: 2.5rem 1.25rem;
            display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; flex-wrap: wrap;
        }
        .store-footer__brand { display: flex; flex-direction: column; gap: .4rem; }
        .store-footer .logo { display: inline-flex; align-items: baseline; gap: .4rem; font-family: 'Cabin Sketch', cursive; font-weight: 700; color: #fff; line-height: 1; }
        .store-footer .logo .events { font-size: 1.5rem; color: var(--teal); }
        .store-footer .logo .by { font-family: var(--font); font-weight: 500; font-size: .75rem; letter-spacing: .04em; color: #9aa0b5; text-transform: uppercase; }
        .store-footer .logo .ck { font-size: 1.5rem; color: #fff; }
        .store-footer__tagline { font-size: .88rem; color: #9aa0b5; margin: 0; }
        .store-footer .cta {
            display: inline-flex; align-items: center; gap: .5rem; white-space: nowrap;
            padding: .7rem 1.35rem; border-radius: 6px; font-weight: 600; font-size: .95rem;
            background: var(--purple); color: #fff; transition: background .15s ease;
        }
        .store-footer .cta:hover { background: var(--purple-dark); color: #fff; }
        .store-footer__bar {
            border-top: 1px solid rgba(255,255,255,.08);
            text-align: center; padding: 1rem 1.25rem; font-size: .8rem; color: #71768c;
        }

        /* On desktop, keep the hero compact so it doesn't dominate the page:
           a shorter, wider crop with a lower height cap. */
        @media (min-width: 768px) {
            .store-hero { aspect-ratio: 4 / 1; max-height: 260px; }
        }

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

    <footer class="store-footer">
        <div class="store-footer__inner">
            <div class="store-footer__brand">
                <a class="logo" href="{{ url('/') }}" aria-label="Events by CK Enterprises">
                    <span class="events">Events</span>
                    <span class="by">by</span>
                    <span class="ck">CK Enterprises</span>
                </a>
                <p class="store-footer__tagline">Branded ticketing and direct payouts for event organisers.</p>
            </div>
            <a class="cta" href="{{ url('/') }}">Start selling your own tickets</a>
        </div>
        <div class="store-footer__bar">
            &copy; {{ date('Y') }} Events by CK Enterprises UK. All rights reserved.
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
