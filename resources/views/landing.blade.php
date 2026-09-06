<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b1739">

    <title>Events by CK Enterprises UK · Simple ticketing for organisations that want to keep more</title>
    <meta
        name="description"
        content="Straightforward UK event ticketing for charities, community organisations and independent organisers. Direct Stripe payouts, branded storefronts, QR check-in and transparent pricing."
    >

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/favicon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>
        :root {
            --navy: #0b1739;
            --navy-2: #12224b;
            --blue: #1f63ff;
            --blue-dark: #174fd0;
            --blue-soft: #edf3ff;

            --ink: #101828;
            --body: #475467;
            --muted: #667085;
            --line: #e4e7ec;

            --surface: #ffffff;
            --surface-alt: #f8fafc;

            --success: #117a55;

            --heading-font: 'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --body-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 80px;
        }

        body {
            margin: 0;
            font-family: var(--body-font);
            color: var(--body);
            background: var(--surface);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        body.menu-open {
            overflow: hidden;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        button,
        input {
            font: inherit;
        }

        button {
            appearance: none;
        }

        h1,
        h2,
        h3 {
            margin: 0;
            color: var(--ink);
            font-family: var(--heading-font);
            letter-spacing: -0.025em;
        }

        h1 {
            max-width: 820px;
            font-size: clamp(2.35rem, 5vw, 4.4rem);
            line-height: 1.02;
        }

        h2 {
            font-size: clamp(1.85rem, 3vw, 2.75rem);
            line-height: 1.12;
        }

        h3 {
            font-size: 1.08rem;
            line-height: 1.3;
        }

        p {
            margin: 0;
        }

        .container {
            width: min(100% - 32px, 1120px);
            margin: 0 auto;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            margin-bottom: 1rem;
            color: var(--blue);
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .eyebrow::before {
            content: "";
            width: 24px;
            height: 2px;
            background: currentColor;
        }

        .btn {
            min-height: 46px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .55rem;

            padding: .72rem 1.15rem;

            border: 1px solid transparent;
            border-radius: 7px;

            font-weight: 700;
            font-size: .95rem;

            cursor: pointer;

            transition:
                background-color .15s ease,
                border-color .15s ease,
                color .15s ease,
                transform .15s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            color: #fff;
            background: var(--blue);
        }

        .btn-primary:hover {
            background: var(--blue-dark);
        }

        .btn-secondary {
            color: var(--ink);
            background: #fff;
            border-color: #d0d5dd;
        }

        .btn-secondary:hover {
            border-color: #98a2b3;
        }

        .btn-on-dark {
            color: var(--navy);
            background: #fff;
        }

        .btn-on-dark:hover {
            background: #f2f4f7;
        }

        .btn-link {
            color: #d7deed;
            background: transparent;
            padding-inline: .25rem;
        }

        .btn-link:hover {
            color: #fff;
            transform: none;
        }

        /* Header */
        .site-header {
            position: sticky;
            top: 0;
            z-index: 100;

            background: rgba(11, 23, 57, .98);
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .header-inner {
            min-height: 68px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 1rem;
        }

        .brand {
            display: inline-flex;
            align-items: baseline;

            min-width: 0;

            color: #fff;
            font-family: var(--heading-font);
            font-weight: 800;
            letter-spacing: -.03em;
            white-space: nowrap;
        }

        .brand-events,
        .brand-ck {
            font-size: 1.05rem;
        }

        .brand-by {
            margin: 0 .36rem;

            color: #8ea0ca;

            font-family: var(--body-font);
            font-size: .68rem;
            font-weight: 600;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .desktop-nav {
            display: flex;
            align-items: center;
            gap: 1.4rem;
        }

        .desktop-nav .nav-link {
            color: #c7d0e6;
            font-size: .92rem;
            font-weight: 600;
        }

        .desktop-nav .nav-link:hover {
            color: #fff;
        }

        .mobile-toggle {
            display: none;

            width: 44px;
            height: 44px;

            align-items: center;
            justify-content: center;

            border: 1px solid rgba(255, 255, 255, .15);
            border-radius: 7px;

            background: transparent;
            color: #fff;

            cursor: pointer;
        }

        .mobile-toggle svg {
            width: 22px;
            height: 22px;
        }

        .mobile-nav {
            display: none;

            background: var(--navy);
            border-top: 1px solid rgba(255, 255, 255, .08);
        }

        .mobile-nav.open {
            display: block;
        }

        .mobile-nav-inner {
            padding-top: .6rem;
            padding-bottom: 1rem;
        }

        .mobile-nav a:not(.btn) {
            min-height: 48px;

            display: flex;
            align-items: center;

            color: #d7deed;
            font-weight: 600;
        }

        .mobile-nav-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .75rem;

            padding-top: .75rem;
        }

        .mobile-nav-actions .btn {
            width: 100%;
        }

        /* Hero */
        .hero {
            position: relative;
            overflow: hidden;

            padding: 5.2rem 0 4.5rem;

            background: var(--navy);
            color: #fff;
        }

        .hero::after {
            content: "";

            position: absolute;
            right: -180px;
            bottom: -240px;

            width: 520px;
            height: 520px;

            border: 1px solid rgba(255, 255, 255, .07);
            border-radius: 50%;
        }

        .hero-grid {
            position: relative;
            z-index: 1;

            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(320px, .7fr);
            gap: 5rem;
            align-items: end;
        }

        .hero h1 {
            color: #fff;
        }

        .hero .lead {
            max-width: 690px;

            margin-top: 1.35rem;

            color: #c9d2e6;
            font-size: clamp(1.03rem, 2vw, 1.2rem);
            line-height: 1.7;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .8rem;

            margin-top: 1.8rem;
        }

        .hero-proof {
            display: flex;
            flex-wrap: wrap;
            gap: .8rem 1.25rem;

            margin-top: 1.45rem;

            color: #98a8c8;
            font-size: .88rem;
        }

        .hero-proof span {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
        }

        .hero-proof span::before {
            content: "✓";
            color: #77d4ad;
            font-weight: 800;
        }

        .hero-price {
            padding: 1.55rem;

            background: #fff;
            color: var(--body);

            border-radius: 10px;

            box-shadow: 0 18px 45px rgba(0, 0, 0, .22);
        }

        .hero-price .small {
            color: var(--muted);

            font-size: .8rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .hero-price .number {
            margin-top: .3rem;

            color: var(--ink);

            font-family: var(--heading-font);
            font-size: 2.15rem;
            font-weight: 800;
            line-height: 1.1;
        }

        .hero-price .number span {
            color: var(--blue);
        }

        .hero-price .desc {
            margin-top: .55rem;
            font-size: .9rem;
        }

        .hero-price dl {
            margin: 1.25rem 0 0;
            padding-top: 1rem;

            border-top: 1px solid var(--line);
        }

        .hero-price dl > div {
            display: flex;
            justify-content: space-between;
            gap: 1rem;

            padding: .42rem 0;

            font-size: .88rem;
        }

        .hero-price dt {
            color: var(--muted);
        }

        .hero-price dd {
            margin: 0;

            color: var(--ink);
            font-weight: 700;
            text-align: right;
        }

        /* Proof strip */
        .proof-strip {
            background: #fff;
            border-bottom: 1px solid var(--line);
        }

        .proof-strip-inner {
            min-height: 74px;

            display: grid;
            grid-template-columns: repeat(4, 1fr);
            align-items: stretch;
        }

        .proof-item {
            display: flex;
            flex-direction: column;
            justify-content: center;

            padding: .9rem 1.2rem;

            border-right: 1px solid var(--line);

            color: var(--muted);
            font-size: .88rem;
        }

        .proof-item:first-child {
            padding-left: 0;
        }

        .proof-item:last-child {
            border-right: 0;
        }

        .proof-item strong {
            display: block;

            margin-bottom: .08rem;

            color: var(--ink);
            font-size: .92rem;
        }

        /* General sections */
        .section {
            padding: 5.25rem 0;
        }

        .section.alt {
            background: var(--surface-alt);
        }

        .section-heading {
            max-width: 730px;
            margin-bottom: 2.5rem;
        }

        .section-heading p {
            margin-top: .8rem;

            color: var(--muted);
            font-size: 1.03rem;
        }

        /* Benefits */
        .benefit-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));

            border-top: 1px solid var(--line);
            border-left: 1px solid var(--line);
        }

        .benefit {
            min-height: 210px;

            padding: 2rem;

            background: #fff;

            border-right: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
        }

        .benefit-number {
            margin-bottom: 1.2rem;

            color: var(--blue);

            font-family: var(--heading-font);
            font-size: .8rem;
            font-weight: 800;
        }

        .benefit p {
            margin-top: .6rem;
            color: var(--muted);
        }

        /* Audience */
        .audience-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            align-items: start;
        }

        .plain-list {
            list-style: none;

            margin: 1.4rem 0 0;
            padding: 0;
        }

        .plain-list li {
            display: flex;
            gap: .7rem;

            padding: .62rem 0;

            border-bottom: 1px solid var(--line);

            color: var(--body);
        }

        .plain-list li::before {
            content: "—";
            color: var(--blue);
            font-weight: 800;
        }

        .audience-note {
            padding: 1.6rem;

            background: var(--blue-soft);
            border-left: 3px solid var(--blue);

            color: #2f4779;
        }

        .audience-note strong {
            display: block;

            margin-bottom: .4rem;

            color: var(--ink);
        }

        /* Pricing */
        .pricing-shell {
            display: grid;
            grid-template-columns: .85fr 1.15fr;
            gap: 2.5rem;
            align-items: start;
        }

        .pricing-copy .price-line {
            margin-top: 1.2rem;

            color: var(--ink);

            font-family: var(--heading-font);
            font-size: 2.65rem;
            font-weight: 800;
            line-height: 1;
        }

        .pricing-copy .price-line span {
            color: var(--blue);
        }

        .pricing-copy .price-sub {
            margin-top: .65rem;
            color: var(--muted);
        }

        .pricing-copy ul {
            margin: 1.3rem 0 0;
            padding-left: 1.15rem;

            color: var(--body);
        }

        .pricing-copy li {
            margin-bottom: .45rem;
        }

        .calc {
            padding: 1.7rem;

            background: #fff;

            border: 1px solid var(--line);
            border-radius: 10px;
        }

        .calc-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .field label {
            display: block;

            margin-bottom: .45rem;

            color: var(--ink);
            font-size: .86rem;
            font-weight: 700;
        }

        .input-wrap {
            position: relative;
        }

        .prefix {
            position: absolute;
            left: .85rem;
            top: 50%;

            transform: translateY(-50%);

            color: var(--muted);
            font-weight: 600;
        }

        .field input {
            width: 100%;
            min-height: 46px;

            padding: .7rem .85rem .7rem 1.75rem;

            border: 1px solid #d0d5dd;
            border-radius: 7px;

            color: var(--ink);
            background: #fff;
        }

        .field input.no-prefix {
            padding-left: .85rem;
        }

        .field input:focus {
            outline: 3px solid rgba(31, 99, 255, .12);
            border-color: var(--blue);
        }

        .fee-mode {
            margin-top: 1rem;
        }

        .toggle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .35rem;

            padding: .3rem;

            background: var(--surface-alt);

            border: 1px solid var(--line);
            border-radius: 8px;
        }

        .toggle button {
            min-height: 40px;

            border: 0;
            border-radius: 6px;

            background: transparent;
            color: var(--muted);

            font-weight: 700;

            cursor: pointer;
        }

        .toggle button.active {
            background: #fff;
            color: var(--blue);

            box-shadow: 0 1px 2px rgba(16, 24, 40, .08);
        }

        .calc-result {
            margin-top: 1.2rem;
            padding-top: 1.2rem;

            border-top: 1px solid var(--line);
        }

        .calc-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;

            padding: .34rem 0;

            font-size: .9rem;
        }

        .calc-row span:last-child {
            color: var(--ink);
            font-weight: 700;
            text-align: right;
        }

        .calc-row.total {
            margin-top: .45rem;
            padding-top: .8rem;

            border-top: 1px solid var(--line);

            font-size: 1rem;
        }

        .calc-row.total span:last-child {
            color: var(--blue);

            font-family: var(--heading-font);
            font-size: 1.35rem;
        }

        .calc-hint {
            margin-top: .8rem;

            color: var(--muted);
            font-size: .78rem;
        }

        /* How it works */
        .steps {
            display: grid;
            grid-template-columns: repeat(4, 1fr);

            border-top: 1px solid var(--line);
            border-left: 1px solid var(--line);
        }

        .step {
            min-height: 210px;

            padding: 1.6rem;

            background: #fff;

            border-right: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
        }

        .step-num {
            margin-bottom: 1.35rem;

            color: var(--blue);

            font-family: var(--heading-font);
            font-size: .82rem;
            font-weight: 800;
        }

        .step p {
            margin-top: .55rem;

            color: var(--muted);
            font-size: .92rem;
        }

        /* Closing CTA */
        .closing {
            padding: 4.4rem 0;

            background: var(--navy);
            color: #fff;
        }

        .closing-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 2rem;
        }

        .closing h2 {
            color: #fff;
        }

        .closing p {
            max-width: 650px;

            margin-top: .6rem;

            color: #b9c4dd;
        }

        .closing-actions {
            display: flex;
            gap: .7rem;
            flex: 0 0 auto;
        }

        /* Footer */
        .site-footer {
            padding: 2.2rem 0;

            background: #08122c;
            color: #8e9aba;

            font-size: .85rem;
        }

        .footer-grid {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1.5rem;
        }

        .footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: .85rem 1.25rem;
        }

        .footer-links a {
            color: #b7c1d9;
        }

        .footer-links a:hover {
            color: #fff;
        }

        .footer-copy {
            margin-top: 1.2rem;
            padding-top: 1.2rem;

            border-top: 1px solid rgba(255, 255, 255, .08);
        }

        /* Tablet */
        @media (max-width: 900px) {
            .desktop-nav {
                display: none;
            }

            .mobile-toggle {
                display: inline-flex;
            }

            .hero {
                padding: 3.8rem 0 3.4rem;
            }

            .hero-grid,
            .pricing-shell,
            .audience-grid {
                grid-template-columns: 1fr;
                gap: 2.2rem;
            }

            .hero-price {
                max-width: 540px;
            }

            .proof-strip-inner {
                grid-template-columns: 1fr 1fr;
            }

            .proof-item:nth-child(2) {
                border-right: 0;
            }

            .proof-item:nth-child(-n+2) {
                border-bottom: 1px solid var(--line);
            }

            .proof-item:nth-child(3) {
                padding-left: 0;
            }

            .steps {
                grid-template-columns: 1fr 1fr;
            }

            .closing-inner {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        /* Mobile */
        @media (max-width: 640px) {
            .container {
                width: min(100% - 28px, 1120px);
            }

            .header-inner {
                min-height: 62px;
            }

            .brand-events,
            .brand-ck {
                font-size: .94rem;
            }

            .brand-by {
                margin-inline: .28rem;
                font-size: .61rem;
            }

            .hero {
                padding: 3rem 0 2.7rem;
            }

            .hero h1 {
                font-size: clamp(2.15rem, 11vw, 3.2rem);
            }

            .hero-actions {
                display: grid;
                grid-template-columns: 1fr;
            }

            .hero-actions .btn {
                width: 100%;
            }

            .hero-proof {
                display: grid;
                gap: .55rem;
            }

            .hero-price {
                padding: 1.3rem;
            }

            .proof-strip-inner {
                grid-template-columns: 1fr;
            }

            .proof-item,
            .proof-item:first-child,
            .proof-item:nth-child(3) {
                padding: .8rem 0;

                border-right: 0;
                border-bottom: 1px solid var(--line);
            }

            .proof-item:last-child {
                border-bottom: 0;
            }

            .section {
                padding: 3.8rem 0;
            }

            .section-heading {
                margin-bottom: 1.8rem;
            }

            .benefit-grid,
            .steps {
                grid-template-columns: 1fr;
            }

            .benefit,
            .step {
                min-height: 0;
                padding: 1.5rem;
            }

            .calc {
                padding: 1.2rem;
            }

            .calc-grid {
                grid-template-columns: 1fr;
            }

            .toggle {
                grid-template-columns: 1fr;
            }

            .calc-row {
                align-items: flex-start;
            }

            .closing {
                padding: 3.2rem 0;
            }

            .closing-actions {
                width: 100%;

                display: grid;
                grid-template-columns: 1fr;
            }

            .footer-grid {
                flex-direction: column;
            }

            .footer-links {
                display: grid;
                grid-template-columns: 1fr 1fr;

                width: 100%;
            }
        }

        @media (max-width: 420px) {
            .brand-ck {
                max-width: 145px;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .mobile-nav-actions {
                grid-template-columns: 1fr;
            }

            .footer-links {
                grid-template-columns: 1fr;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            *,
            *::before,
            *::after {
                transition-duration: .01ms !important;
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
            }
        }
    </style>
</head>

<body>
    <header class="site-header">
        <div class="container header-inner">
            <a
                class="brand"
                href="{{ url('/') }}"
                aria-label="Events by CK Enterprises UK"
            >
                <span class="brand-events">Events</span>
                <span class="brand-by">by</span>
                <span class="brand-ck">CK Enterprises UK</span>
            </a>

            <nav class="desktop-nav" aria-label="Primary navigation">
                <a class="nav-link" href="#why">Why us</a>
                <a class="nav-link" href="#pricing">Pricing</a>
                <a class="nav-link" href="#how">How it works</a>
                <a class="nav-link" href="{{ route('trust.index') }}">Trust &amp; Legal</a>

                @auth
                    <a class="btn btn-primary" href="{{ route('dashboard.home') }}">
                        Dashboard
                    </a>
                @else
                    <a class="nav-link" href="{{ url('/login') }}">Log in</a>
                    <a class="btn btn-primary" href="{{ url('/register') }}">
                        Get started
                    </a>
                @endauth
            </nav>

            <button
                class="mobile-toggle"
                id="mobile-menu-toggle"
                type="button"
                aria-expanded="false"
                aria-controls="mobile-menu"
                aria-label="Open navigation"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    aria-hidden="true"
                >
                    <path d="M4 7h16M4 12h16M4 17h16"/>
                </svg>
            </button>
        </div>

        <div class="mobile-nav" id="mobile-menu">
            <nav class="container mobile-nav-inner" aria-label="Mobile navigation">
                <a href="#why">Why us</a>
                <a href="#pricing">Pricing</a>
                <a href="#how">How it works</a>
                <a href="{{ route('trust.index') }}">Trust &amp; Legal</a>

                <div class="mobile-nav-actions">
                    @auth
                        <a class="btn btn-primary" href="{{ route('dashboard.home') }}">
                            Dashboard
                        </a>
                    @else
                        <a class="btn btn-secondary" href="{{ url('/login') }}">
                            Log in
                        </a>

                        <a class="btn btn-primary" href="{{ url('/register') }}">
                            Get started
                        </a>
                    @endauth
                </div>
            </nav>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="container hero-grid">
                <div>
                    <span class="eyebrow">
                        Ticketing for charities &amp; independent organisers
                    </span>

                    <h1>Your event. Your customers. Your money.</h1>

                    <p class="lead">
                        Sell tickets through your own branded storefront, take payments through your own
                        connected Stripe account, and keep more of every sale with straightforward platform pricing.
                    </p>

                    <div class="hero-actions">
                        @auth
                            <a class="btn btn-primary" href="{{ route('dashboard.home') }}">
                                Go to dashboard
                            </a>
                        @else
                            <a class="btn btn-primary" href="{{ url('/register') }}">
                                Start selling tickets
                            </a>
                        @endauth

                        <a class="btn btn-link" href="#pricing">
                            See the numbers →
                        </a>
                    </div>

                    <div class="hero-proof" aria-label="Key benefits">
                        <span>No monthly subscription</span>
                        <span>Direct Stripe payouts</span>
                        <span>UK-based support</span>
                    </div>
                </div>

                <aside class="hero-price" aria-label="Example platform cost">
                    <div class="small">Simple platform pricing</div>

                    <div class="number">
                        <span>
                            {{ number_format($feePercent, $feePercent == (int) $feePercent ? 0 : 2) }}%
                        </span>
                        per ticket
                    </div>

                    <p class="desc">
                        No fixed CK Enterprises UK charge per ticket and no monthly platform subscription.
                    </p>

                    <dl>
                        <div>
                            <dt>Example ticket sales</dt>
                            <dd>£10,000</dd>
                        </div>

                        <div>
                            <dt>Platform fee</dt>
                            <dd>£{{ number_format(10000 * ($feePercent / 100), 2) }}</dd>
                        </div>

                        <div>
                            <dt>Funds</dt>
                            <dd>Your Stripe account</dd>
                        </div>
                    </dl>
                </aside>
            </div>
        </section>

        <div class="proof-strip" aria-label="Platform principles">
            <div class="container proof-strip-inner">
                <div class="proof-item">
                    <strong>Your own storefront</strong>
                    Your branding, not a marketplace.
                </div>

                <div class="proof-item">
                    <strong>No buyer accounts</strong>
                    Less friction at checkout.
                </div>

                <div class="proof-item">
                    <strong>No advertising trackers</strong>
                    Customer data stays focused on the event.
                </div>

                <div class="proof-item">
                    <strong>Phone-based check-in</strong>
                    Scan QR tickets without specialist hardware.
                </div>
            </div>
        </div>

        <section class="section" id="why">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow">Why Events by CK Enterprises UK</span>

                    <h2>
                        Built to help organisations raise more, keep more and spend less time administering events.
                    </h2>

                    <p>
                        We have deliberately kept the platform focused on the things small and community-led
                        organisers actually need.
                    </p>
                </div>

                <div class="benefit-grid">
                    <article class="benefit">
                        <div class="benefit-number">01</div>

                        <h3>Keep more of each ticket sale</h3>

                        <p>
                            Transparent percentage pricing without a separate fixed CK Enterprises UK charge
                            on every ticket.
                        </p>
                    </article>

                    <article class="benefit">
                        <div class="benefit-number">02</div>

                        <h3>Your funds go through your Stripe account</h3>

                        <p>
                            Connect Stripe and receive event income through your own payment account rather than
                            a pooled platform wallet.
                        </p>
                    </article>

                    <article class="benefit">
                        <div class="benefit-number">03</div>

                        <h3>Your customers are there for your organisation</h3>

                        <p>
                            Your storefront is focused on your organisation, with no competing-event marketplace
                            or advertising feed.
                        </p>
                    </article>

                    <article class="benefit">
                        <div class="benefit-number">04</div>

                        <h3>Support from a UK technology partner</h3>

                        <p>
                            CK Enterprises UK already supports charities and small organisations, so help is
                            practical and human when you need it.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section class="section alt">
            <div class="container audience-grid">
                <div>
                    <span class="eyebrow">A practical fit</span>

                    <h2>
                        Designed for organisations that run real-world events without a dedicated ticketing team.
                    </h2>

                    <ul class="plain-list">
                        <li>Charity fundraisers and community events</li>
                        <li>Scout groups, clubs and voluntary organisations</li>
                        <li>PTAs, schools and local groups</li>
                        <li>Independent venues and small event organisers</li>
                    </ul>
                </div>

                <div class="audience-note">
                    <strong>Not trying to become another event marketplace.</strong>

                    Events by CK Enterprises UK is built as a straightforward service for the organiser:
                    branded sales, payments, tickets, check-in and reporting.
                </div>
            </div>
        </section>

        <section class="section" id="pricing">
            <div class="container pricing-shell">
                <div class="pricing-copy">
                    <span class="eyebrow">Transparent pricing</span>

                    <h2>
                        Know what the platform costs before you sell a ticket.
                    </h2>

                    <div class="price-line">
                        <span>
                            {{ number_format($feePercent, $feePercent == (int) $feePercent ? 0 : 2) }}%
                        </span>
                        platform fee
                    </div>

                    <p class="price-sub">
                        No setup charge. No monthly subscription. Stripe's payment-processing charges are separate.
                    </p>

                    <ul>
                        <li>
                            Absorb the platform fee or include it in the customer-facing ticket price.
                        </li>
                        <li>
                            Mandatory customer pricing is shown transparently before checkout.
                        </li>
                        <li>
                            Eligible charities and community organisations can discuss tailored rates.
                        </li>
                    </ul>
                </div>

                <div class="calc" data-fee="{{ $feePercent }}">
                    <div class="calc-grid">
                        <div class="field">
                            <label for="calc-price">Ticket price</label>

                            <div class="input-wrap">
                                <span class="prefix">£</span>

                                <input
                                    type="number"
                                    id="calc-price"
                                    min="0"
                                    step="0.50"
                                    value="25.00"
                                    inputmode="decimal"
                                >
                            </div>
                        </div>

                        <div class="field">
                            <label for="calc-qty">Tickets sold</label>

                            <input
                                type="number"
                                id="calc-qty"
                                class="no-prefix"
                                min="1"
                                step="1"
                                value="100"
                                inputmode="numeric"
                            >
                        </div>
                    </div>

                    <div class="fee-mode">
                        <div class="field">
                            <label>How do you want to handle the platform fee?</label>

                            <div class="toggle" role="group" aria-label="Fee handling">
                                <button
                                    type="button"
                                    id="mode-absorb"
                                    class="active"
                                >
                                    Organisation absorbs it
                                </button>

                                <button
                                    type="button"
                                    id="mode-passon"
                                >
                                    Include it in ticket price
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="calc-result" aria-live="polite">
                        <div class="calc-row">
                            <span id="result-mode-label">Ticket price</span>
                            <span>£<span id="result-buyer-price">25.00</span></span>
                        </div>

                        <div class="calc-row">
                            <span>Platform fee per ticket</span>
                            <span>£<span id="result-fee-each">0.00</span></span>
                        </div>

                        <div class="calc-row">
                            <span>Total platform fee</span>
                            <span>£<span id="result-fee-total">0.00</span></span>
                        </div>

                        <div class="calc-row total">
                            <span id="result-payout-label">You receive before Stripe fees</span>
                            <span>£<span id="result-payout">0.00</span></span>
                        </div>

                        <p class="calc-hint">
                            Estimate excludes Stripe's own payment-processing charges.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section class="section alt" id="how">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow">How it works</span>

                    <h2>
                        From setup to the front door in four steps.
                    </h2>
                </div>

                <div class="steps">
                    <article class="step">
                        <div class="step-num">01</div>

                        <h3>Create your organisation</h3>

                        <p>
                            Add your organisation details, branding and the people who need access.
                        </p>
                    </article>

                    <article class="step">
                        <div class="step-num">02</div>

                        <h3>Connect Stripe</h3>

                        <p>
                            Link your own Stripe account so ticket payments settle through your organisation.
                        </p>
                    </article>

                    <article class="step">
                        <div class="step-num">03</div>

                        <h3>Publish your event</h3>

                        <p>
                            Create ticket types, set capacity and share your branded storefront link.
                        </p>
                    </article>

                    <article class="step">
                        <div class="step-num">04</div>

                        <h3>Scan tickets at the door</h3>

                        <p>
                            Use a modern phone browser to validate QR tickets and keep track of arrivals.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section class="closing">
            <div class="container closing-inner">
                <div>
                    <h2>Planning an event?</h2>

                    <p>
                        Start with the platform, or speak to CK Enterprises UK if you want to discuss
                        your organisation, expected ticket volume or charity pricing.
                    </p>
                </div>

                <div class="closing-actions">
                    @auth
                        <a class="btn btn-on-dark" href="{{ route('dashboard.home') }}">
                            Go to dashboard
                        </a>
                    @else
                        <a class="btn btn-on-dark" href="{{ url('/register') }}">
                            Get started
                        </a>

                        <a
                            class="btn btn-link"
                            href="https://ckenterprises.co.uk/#contact"
                            target="_blank"
                            rel="noopener"
                        >
                            Talk to us
                        </a>
                    @endauth
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <a
                    class="brand"
                    href="{{ url('/') }}"
                    aria-label="Events by CK Enterprises UK"
                >
                    <span class="brand-events">Events</span>
                    <span class="brand-by">by</span>
                    <span class="brand-ck">CK Enterprises UK</span>
                </a>

                <div class="footer-links">
                    <a href="#why">Why us</a>
                    <a href="#pricing">Pricing</a>
                    <a href="#how">How it works</a>
                    <a href="{{ route('trust.index') }}">Trust &amp; Legal</a>

                    <a
                        href="https://ckenterprises.co.uk/"
                        target="_blank"
                        rel="noopener"
                    >
                        CK Enterprises UK
                    </a>

                    @guest
                        <a href="{{ url('/login') }}">Log in</a>
                    @endguest
                </div>
            </div>

            <div class="footer-copy">
                &copy; {{ date('Y') }} CK Enterprises UK.
                Events by CK Enterprises UK is operated by CK Enterprises Group Limited.
            </div>
        </div>
    </footer>

    <script>
        (function () {
            /*
             * Mobile navigation
             */
            var toggle = document.getElementById('mobile-menu-toggle');
            var menu = document.getElementById('mobile-menu');

            if (toggle && menu) {
                function closeMenu() {
                    menu.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                    toggle.setAttribute('aria-label', 'Open navigation');
                    document.body.classList.remove('menu-open');
                }

                toggle.addEventListener('click', function () {
                    var isOpen = menu.classList.toggle('open');

                    toggle.setAttribute(
                        'aria-expanded',
                        isOpen ? 'true' : 'false'
                    );

                    toggle.setAttribute(
                        'aria-label',
                        isOpen ? 'Close navigation' : 'Open navigation'
                    );

                    document.body.classList.toggle('menu-open', isOpen);
                });

                menu.querySelectorAll('a').forEach(function (link) {
                    link.addEventListener('click', closeMenu);
                });

                window.addEventListener('resize', function () {
                    if (window.innerWidth > 900) {
                        closeMenu();
                    }
                });

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        closeMenu();
                    }
                });
            }

            /*
             * Pricing calculator
             */
            var root = document.querySelector('.calc');

            if (!root) {
                return;
            }

            var feePct = Math.max(
                0,
                parseFloat(root.getAttribute('data-fee')) || 0
            );

            var priceEl = document.getElementById('calc-price');
            var qtyEl = document.getElementById('calc-qty');

            var absorbBtn = document.getElementById('mode-absorb');
            var passOnBtn = document.getElementById('mode-passon');

            var outModeLabel = document.getElementById('result-mode-label');
            var outBuyerPrice = document.getElementById('result-buyer-price');
            var outFeeEach = document.getElementById('result-fee-each');
            var outFeeTotal = document.getElementById('result-fee-total');
            var outPayoutLabel = document.getElementById('result-payout-label');
            var outPayout = document.getElementById('result-payout');

            var passOn = false;

            function money(value) {
                return value.toLocaleString('en-GB', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            }

            function recalc() {
                var price = Math.max(
                    0,
                    parseFloat(priceEl.value) || 0
                );

                var qty = Math.max(
                    0,
                    parseInt(qtyEl.value, 10) || 0
                );

                /*
                 * Fee is rounded to the penny per ticket,
                 * then multiplied by quantity.
                 */
                var feeEach = Math.round(
                    price * (feePct / 100) * 100
                ) / 100;

                var feeTotal = Math.round(
                    feeEach * qty * 100
                ) / 100;

                var buyerPrice;
                var payout;

                if (passOn) {
                    buyerPrice = price + feeEach;

                    payout = Math.round(
                        price * qty * 100
                    ) / 100;

                    outModeLabel.textContent =
                        'Ticket price including platform fee';

                    outPayoutLabel.textContent =
                        'You receive before Stripe fees';
                } else {
                    buyerPrice = price;

                    payout = Math.round(
                        (price * qty - feeTotal) * 100
                    ) / 100;

                    outModeLabel.textContent =
                        'Ticket price';

                    outPayoutLabel.textContent =
                        'You receive before Stripe fees';
                }

                outBuyerPrice.textContent = money(buyerPrice);
                outFeeEach.textContent = money(feeEach);
                outFeeTotal.textContent = money(feeTotal);
                outPayout.textContent = money(Math.max(0, payout));
            }

            function setMode(isPassOn) {
                passOn = isPassOn;

                absorbBtn.classList.toggle(
                    'active',
                    !isPassOn
                );

                passOnBtn.classList.toggle(
                    'active',
                    isPassOn
                );

                recalc();
            }

            [priceEl, qtyEl].forEach(function (element) {
                element.addEventListener(
                    'input',
                    recalc
                );
            });

            absorbBtn.addEventListener(
                'click',
                function () {
                    setMode(false);
                }
            );

            passOnBtn.addEventListener(
                'click',
                function () {
                    setMode(true);
                }
            );

            recalc();
        })();
    </script>
</body>
</html>
