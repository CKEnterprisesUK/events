<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f1425">

    <title>Events by CK Enterprises UK · Straightforward event ticketing</title>
    <meta
        name="description"
        content="Straightforward event ticketing for charities, community organisations and small event organisers. Direct Stripe payouts, branded storefronts, QR check-in and transparent pricing."
    >

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/favicon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Cabin+Sketch:wght@700&family=Inter:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>
        :root {
            /* Existing Events by CK Enterprises UK palette */
            --navy: #0f1425;
            --navy-2: #1b1f2e;
            --purple: #674df3;
            --purple-dark: #5238d6;
            --purple-soft: #f4f1ff;
            --teal: #30f0b6;
            --teal-dark: #149d79;
            --teal-soft: #e8fff7;

            --ink: #101828;
            --body: #475467;
            --muted: #667085;
            --line: #e4e7ec;
            --line-strong: #d0d5dd;
            --surface: #ffffff;
            --surface-alt: #fbfbfe;
            --surface-soft: #f8fafc;

            --shadow-sm: 0 2px 8px rgba(16, 24, 40, .05);
            --shadow-md: 0 14px 34px rgba(16, 24, 40, .09);
            --shadow-lg: 0 24px 60px rgba(16, 24, 40, .12);

            --heading-font: 'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --logo-font: 'Cabin Sketch', cursive;
            --body-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        * { box-sizing: border-box; }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 88px;
        }

        body {
            margin: 0;
            color: var(--body);
            background: var(--surface);
            font-family: var(--body-font);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        body.menu-open { overflow: hidden; }

        a {
            color: inherit;
            text-decoration: none;
        }

        img {
            display: block;
            max-width: 100%;
        }

        button,
        input {
            font: inherit;
        }

        button { appearance: none; }

        h1,
        h2,
        h3 {
            margin: 0;
            color: var(--ink);
            font-family: var(--heading-font);
            letter-spacing: -.028em;
        }

        h1 {
            max-width: 820px;
            font-size: clamp(2.55rem, 5.6vw, 4.7rem);
            line-height: .98;
        }

        h2 {
            font-size: clamp(1.95rem, 3.2vw, 2.85rem);
            line-height: 1.08;
        }

        h3 {
            font-size: 1.08rem;
            line-height: 1.3;
        }

        p { margin: 0; }

        .container {
            width: min(100% - 36px, 1180px);
            margin-inline: auto;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            margin-bottom: .85rem;
            color: var(--purple);
            font-size: .76rem;
            font-weight: 800;
            letter-spacing: .085em;
            text-transform: uppercase;
        }

        .eyebrow::before {
            content: "";
            width: 22px;
            height: 2px;
            background: currentColor;
        }

        .btn {
            min-height: 47px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            padding: .72rem 1.15rem;
            border: 1px solid transparent;
            border-radius: 8px;
            font-size: .94rem;
            font-weight: 700;
            cursor: pointer;
            transition:
                transform .15s ease,
                background-color .15s ease,
                border-color .15s ease,
                box-shadow .15s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            color: #fff;
            background: var(--purple);
            box-shadow: 0 8px 18px rgba(103, 77, 243, .19);
        }

        .btn-primary:hover {
            background: var(--purple-dark);
            box-shadow: 0 10px 22px rgba(103, 77, 243, .24);
        }

        .btn-secondary {
            color: var(--ink);
            background: #fff;
            border-color: var(--line-strong);
        }

        .btn-secondary:hover {
            border-color: #98a2b3;
            box-shadow: var(--shadow-sm);
        }

        .btn-dark {
            color: #fff;
            background: var(--navy);
        }

        .btn-dark:hover {
            background: var(--navy-2);
        }

        .text-link {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            color: var(--purple);
            font-weight: 700;
        }

        .text-link:hover { text-decoration: underline; }

        /* Header */
        .site-header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255, 255, 255, .96);
            border-bottom: 1px solid rgba(228, 231, 236, .9);
            backdrop-filter: blur(14px);
        }

        .header-inner {
            min-height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.25rem;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: .62rem;
            min-width: 0;
        }

        .brand-mark {
            width: 36px;
            height: 36px;
            flex: 0 0 36px;
            border-radius: 8px;
            object-fit: contain;
        }

        .brand-copy {
            display: flex;
            align-items: baseline;
            min-width: 0;
            font-family: var(--logo-font);
            font-weight: 700;
            white-space: nowrap;
        }

        .brand-events,
        .brand-ck {
            font-size: 1.13rem;
            line-height: 1;
        }

        .brand-events { color: var(--purple); }
        .brand-ck { color: var(--navy); }

        .brand-by {
            margin-inline: .32rem;
            color: #8b95aa;
            font-family: var(--body-font);
            font-size: .62rem;
            font-weight: 700;
            letter-spacing: .055em;
            text-transform: uppercase;
        }

        .desktop-nav {
            display: flex;
            align-items: center;
            gap: 1.2rem;
        }

        .desktop-nav .nav-link {
            color: #344054;
            font-size: .9rem;
            font-weight: 600;
        }

        .desktop-nav .nav-link:hover { color: var(--purple); }

        .mobile-toggle {
            display: none;
            width: 44px;
            height: 44px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--navy);
            background: #fff;
            cursor: pointer;
        }

        .mobile-toggle svg {
            width: 22px;
            height: 22px;
        }

        .mobile-nav {
            display: none;
            background: #fff;
            border-top: 1px solid var(--line);
        }

        .mobile-nav.open { display: block; }

        .mobile-nav-inner {
            padding-block: .65rem 1rem;
        }

        .mobile-nav a:not(.btn) {
            min-height: 46px;
            display: flex;
            align-items: center;
            color: #344054;
            font-weight: 600;
        }

        .mobile-nav-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .7rem;
            padding-top: .75rem;
        }

        /* Hero */
        .hero {
            position: relative;
            overflow: hidden;
            padding: 5rem 0 4.4rem;
            background:
                radial-gradient(circle at 86% 15%, rgba(48, 240, 182, .14), transparent 25%),
                radial-gradient(circle at 82% 35%, rgba(103, 77, 243, .12), transparent 36%),
                linear-gradient(180deg, #fff 0%, #fbfbfe 100%);
        }

        .hero::after {
            content: "";
            position: absolute;
            inset: auto -170px -240px auto;
            width: 470px;
            height: 470px;
            border: 1px solid rgba(103, 77, 243, .12);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(340px, .72fr);
            gap: 3rem;
            align-items: center;
        }

        .hero h1 span { color: var(--purple); }

        .hero .lead {
            max-width: 720px;
            margin-top: 1.3rem;
            color: #475467;
            font-size: clamp(1.02rem, 1.8vw, 1.14rem);
            line-height: 1.72;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            margin-top: 1.65rem;
        }

        .hero-proof {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .8rem;
            margin-top: 1.65rem;
        }

        .hero-proof-item {
            display: flex;
            gap: .72rem;
            align-items: flex-start;
        }

        .hero-proof-icon {
            width: 36px;
            height: 36px;
            flex: 0 0 36px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            color: var(--purple);
            background: var(--purple-soft);
            font-weight: 800;
        }

        .hero-proof-item:nth-child(2) .hero-proof-icon {
            color: var(--teal-dark);
            background: var(--teal-soft);
        }

        .hero-proof-item strong {
            display: block;
            color: var(--ink);
            font-size: .84rem;
        }

        .hero-proof-item small {
            display: block;
            margin-top: .08rem;
            color: var(--muted);
            font-size: .76rem;
            line-height: 1.4;
        }

        .hero-visual {
            position: relative;
            min-height: 400px;
        }

        .hero-browser {
            position: relative;
            overflow: hidden;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            transform: rotate(1.2deg);
        }

        .browser-bar {
            display: flex;
            align-items: center;
            gap: .4rem;
            height: 34px;
            padding: 0 .85rem;
            background: #f2f4f7;
            border-bottom: 1px solid var(--line);
        }

        .browser-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #c7cdd6;
        }

        .hero-browser img {
            width: 100%;
            aspect-ratio: 16 / 10;
            object-fit: cover;
            object-position: top;
        }

        .hero-floating {
            position: absolute;
            left: -28px;
            bottom: -22px;
            width: min(290px, 78%);
            padding: 1rem 1.05rem;
            background: var(--navy);
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            color: #fff;
        }

        .hero-floating strong {
            display: block;
            font-family: var(--heading-font);
            font-size: 1rem;
        }

        .hero-floating p {
            margin-top: .28rem;
            color: #c7d0e6;
            font-size: .81rem;
            line-height: 1.45;
        }

        .hero-floating .accent {
            color: var(--teal);
        }

        /* Shared section styles */
        .section {
            padding: 4.5rem 0;
        }

        .section.alt { background: var(--surface-alt); }
        .section.soft { background: var(--surface-soft); }

        .section-heading {
            max-width: 850px;
            margin-bottom: 2rem;
        }

        .section-heading.with-aside {
            max-width: none;
            display: grid;
            grid-template-columns: minmax(0, .9fr) minmax(280px, .65fr);
            gap: 2.5rem;
            align-items: end;
        }

        .section-heading p {
            margin-top: .7rem;
            color: var(--muted);
        }

        .section-heading.with-aside > p {
            margin-top: 0;
            padding-bottom: .15rem;
        }

        /* Product showcase */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .product-card {
            overflow: hidden;
            display: flex;
            flex-direction: column;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 13px;
            box-shadow: var(--shadow-sm);
            transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
        }

        .product-card:hover {
            transform: translateY(-3px);
            border-color: #d7d1ff;
            box-shadow: var(--shadow-md);
        }

        .product-media {
            position: relative;
            overflow: hidden;
            background: #eef1f6;
            border-bottom: 1px solid var(--line);
        }

        .product-media img {
            width: 100%;
            aspect-ratio: 16 / 10.4;
            object-fit: cover;
            object-position: center;
            transition: transform .22s ease;
        }

        .product-card:hover .product-media img { transform: scale(1.015); }

        .product-card.product-ui .product-media img {
            object-position: top;
        }

        .product-body {
            min-height: 128px;
            display: flex;
            flex-direction: column;
            padding: 1rem 1.05rem 1.1rem;
        }

        .product-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .8rem;
        }

        .product-arrow {
            color: var(--purple);
            font-size: 1.15rem;
            font-weight: 800;
        }

        .product-body p {
            margin-top: .42rem;
            color: var(--muted);
            font-size: .88rem;
            line-height: 1.5;
        }

        .asset-note {
            margin-top: 1rem;
            padding: .85rem 1rem;
            color: #605a79;
            background: var(--purple-soft);
            border: 1px solid #e8e3ff;
            border-radius: 9px;
            font-size: .82rem;
        }

        .asset-note code {
            color: var(--purple-dark);
            font-weight: 700;
        }

        /* Benefit / trust cards */
        .benefit-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .9rem;
        }

        .benefit {
            min-height: 190px;
            padding: 1.35rem;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 11px;
        }

        .benefit-icon,
        .step-icon,
        .trust-icon {
            width: 43px;
            height: 43px;
            display: grid;
            place-items: center;
            margin-bottom: 1rem;
            border-radius: 10px;
            color: var(--purple);
            background: var(--purple-soft);
        }

        .benefit-icon svg,
        .step-icon svg,
        .trust-icon svg {
            width: 22px;
            height: 22px;
        }

        .benefit p {
            margin-top: .5rem;
            color: var(--muted);
            font-size: .9rem;
        }

        /* Steps */
        .steps {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .9rem;
        }

        .step {
            position: relative;
            min-height: 185px;
            padding: 1.3rem;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 11px;
        }

        .step-number {
            position: absolute;
            top: 1.25rem;
            right: 1.25rem;
            color: #8a7df7;
            font-family: var(--heading-font);
            font-size: .77rem;
            font-weight: 800;
        }

        .step p {
            margin-top: .5rem;
            color: var(--muted);
            font-size: .89rem;
        }

        /* Pricing */
        .pricing-shell {
            display: grid;
            grid-template-columns: minmax(0, .78fr) minmax(380px, 1fr);
            gap: 2.2rem;
            align-items: start;
        }

        .pricing-copy .price-line {
            margin-top: 1rem;
            color: var(--ink);
            font-family: var(--heading-font);
            font-size: 2.55rem;
            font-weight: 800;
            line-height: 1;
        }

        .pricing-copy .price-line span { color: var(--purple); }

        .pricing-copy .price-sub {
            margin-top: .65rem;
            color: var(--muted);
        }

        .pricing-copy ul {
            margin: 1.15rem 0 0;
            padding-left: 1.15rem;
        }

        .pricing-copy li { margin-bottom: .42rem; }

        .calc {
            padding: 1.35rem;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
        }

        .calc-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .9rem;
        }

        .field label {
            display: block;
            margin-bottom: .4rem;
            color: var(--ink);
            font-size: .84rem;
            font-weight: 700;
        }

        .input-wrap { position: relative; }

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
            border: 1px solid var(--line-strong);
            border-radius: 8px;
            color: var(--ink);
            background: #fff;
        }

        .field input.no-prefix { padding-left: .85rem; }

        .field input:focus {
            outline: 3px solid rgba(103, 77, 243, .12);
            border-color: var(--purple);
        }

        .fee-mode { margin-top: .95rem; }

        .toggle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .35rem;
            padding: .3rem;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        .toggle button {
            min-height: 42px;
            padding: .6rem .8rem;
            border: 0;
            border-radius: 6px;
            color: var(--muted);
            background: transparent;
            font-weight: 700;
            cursor: pointer;
        }

        .toggle button.active {
            color: var(--purple);
            background: #fff;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .08);
        }

        .calc-result {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--line);
        }

        .calc-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: .32rem 0;
            font-size: .9rem;
        }

        .calc-row span:last-child {
            color: var(--ink);
            font-weight: 700;
            text-align: right;
        }

        .calc-row.total {
            margin-top: .35rem;
            padding-top: .75rem;
            border-top: 1px solid var(--line);
            font-size: 1rem;
        }

        .calc-row.total span:last-child {
            color: var(--purple);
            font-family: var(--heading-font);
            font-size: 1.3rem;
        }

        .calc-hint {
            margin-top: .65rem;
            color: var(--muted);
            font-size: .78rem;
        }

        /* Trust section */
        .trust-panel {
            padding: 2rem;
            background:
                linear-gradient(120deg, rgba(103, 77, 243, .08), rgba(48, 240, 182, .08)),
                #fff;
            border: 1px solid #e2e0f3;
            border-radius: 15px;
        }

        .trust-panel-head {
            display: grid;
            grid-template-columns: minmax(0, .9fr) minmax(280px, .7fr);
            gap: 2rem;
            align-items: end;
            margin-bottom: 1.7rem;
        }

        .trust-panel-head p { color: var(--muted); }

        .trust-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            background: rgba(255, 255, 255, .72);
            border: 1px solid rgba(228, 231, 236, .9);
            border-radius: 11px;
            overflow: hidden;
        }

        .trust-item {
            padding: 1.3rem;
            border-right: 1px solid var(--line);
        }

        .trust-item:last-child { border-right: 0; }

        .trust-item:nth-child(2) .trust-icon {
            color: var(--teal-dark);
            background: var(--teal-soft);
        }

        .trust-item p {
            margin-top: .4rem;
            color: var(--muted);
            font-size: .86rem;
        }

        /* Closing CTA */
        .closing {
            padding: 3.6rem 0;
            background:
                radial-gradient(circle at 10% 50%, rgba(48, 240, 182, .08), transparent 25%),
                linear-gradient(135deg, #151b34 0%, var(--navy) 100%);
            color: #fff;
        }

        .closing-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 2rem;
        }

        .closing h2 { color: #fff; }

        .closing p {
            max-width: 650px;
            margin-top: .55rem;
            color: #b9c4dd;
        }

        .closing-actions {
            display: flex;
            gap: .7rem;
            flex: 0 0 auto;
        }

        .closing .btn-secondary {
            color: #fff;
            background: transparent;
            border-color: rgba(255, 255, 255, .25);
        }

        .closing .btn-secondary:hover {
            border-color: rgba(255, 255, 255, .55);
            background: rgba(255, 255, 255, .05);
        }

        /* Footer */
        .site-footer {
            padding: 2rem 0 1.5rem;
            background: #08122c;
            color: #8e9aba;
            font-size: .84rem;
        }

        .site-footer .brand-ck { color: #fff; }
        .site-footer .brand-events { color: var(--teal); }

        .footer-grid {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1.4rem;
        }

        .footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: .8rem 1.1rem;
        }

        .footer-links a { color: #b7c1d9; }
        .footer-links a:hover { color: #fff; }

        .footer-copy {
            display: flex;
            justify-content: space-between;
            gap: 1.5rem;
            margin-top: 1.2rem;
            padding-top: 1.1rem;
            border-top: 1px solid rgba(255, 255, 255, .08);
        }

        .footer-company {
            max-width: 560px;
            text-align: right;
        }

        /* Responsive */
        @media (max-width: 1020px) {
            .desktop-nav { display: none; }
            .mobile-toggle { display: inline-flex; }

            .hero-grid,
            .pricing-shell,
            .trust-panel-head {
                grid-template-columns: 1fr;
            }

            .hero-visual {
                width: min(760px, 100%);
                min-height: 0;
                margin-inline: auto;
            }

            .hero-proof,
            .product-grid,
            .benefit-grid,
            .steps {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .section-heading.with-aside {
                grid-template-columns: 1fr;
                gap: .8rem;
            }

            .section-heading.with-aside > p { max-width: 720px; }
        }

        @media (max-width: 720px) {
            .hero {
                padding: 3.5rem 0 3.4rem;
            }

            .hero h1 {
                font-size: clamp(2.35rem, 12vw, 3.3rem);
            }

            .hero-actions {
                display: grid;
                grid-template-columns: 1fr;
            }

            .hero-actions .btn { width: 100%; }

            .hero-proof,
            .product-grid,
            .benefit-grid,
            .steps,
            .trust-grid,
            .calc-grid,
            .toggle {
                grid-template-columns: 1fr;
            }

            .hero-floating {
                position: relative;
                left: 0;
                bottom: auto;
                width: auto;
                margin-top: .8rem;
            }

            .section {
                padding: 3.4rem 0;
            }

            .trust-panel { padding: 1.25rem; }

            .trust-item {
                border-right: 0;
                border-bottom: 1px solid var(--line);
            }

            .trust-item:last-child { border-bottom: 0; }

            .closing-inner {
                flex-direction: column;
                align-items: flex-start;
            }

            .closing-actions {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr;
            }

            .footer-grid,
            .footer-copy {
                flex-direction: column;
            }

            .footer-company { text-align: left; }

            .footer-links {
                display: grid;
                grid-template-columns: 1fr 1fr;
                width: 100%;
            }
        }

        @media (max-width: 500px) {
            .container { width: min(100% - 28px, 1180px); }

            .brand-events,
            .brand-ck {
                font-size: .98rem;
            }

            .brand-by {
                margin-inline: .25rem;
                font-size: .57rem;
            }

            .brand-mark {
                width: 32px;
                height: 32px;
                flex-basis: 32px;
            }

            .mobile-nav-actions,
            .footer-links {
                grid-template-columns: 1fr;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }

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
            <a class="brand" href="{{ url('/') }}" aria-label="Events by CK Enterprises UK home">
                <img class="brand-mark" src="{{ asset('images/favicon.png') }}" alt="" aria-hidden="true">
                <span class="brand-copy">
                    <span class="brand-events">Events</span>
                    <span class="brand-by">by</span>
                    <span class="brand-ck">CK Enterprises UK</span>
                </span>
            </a>

            <nav class="desktop-nav" aria-label="Primary navigation">
                <a class="nav-link" href="#why">Why us</a>
                <a class="nav-link" href="#pricing">Pricing</a>
                <a class="nav-link" href="#how">How it works</a>
                <a class="nav-link" href="{{ route('trust.index') }}">Trust &amp; Legal</a>
                <a class="nav-link" href="{{ url('/contact') }}">Contact</a>

                @auth
                    <a class="btn btn-primary" href="{{ route('dashboard.home') }}">Dashboard</a>
                @else
                    <a class="nav-link" href="{{ url('/login') }}">Log in</a>
                    <a class="btn btn-primary" href="{{ url('/register') }}">Get started</a>
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
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
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
                <a href="{{ url('/contact') }}">Contact</a>

                <div class="mobile-nav-actions">
                    @auth
                        <a class="btn btn-primary" href="{{ route('dashboard.home') }}">Dashboard</a>
                    @else
                        <a class="btn btn-secondary" href="{{ url('/login') }}">Log in</a>
                        <a class="btn btn-primary" href="{{ url('/register') }}">Get started</a>
                    @endauth
                </div>
            </nav>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="container hero-grid">
                <div>
                    <span class="eyebrow">Straightforward ticketing for organisations that run real events</span>

                    <h1>Your event. Your customers. <span>Your money.</span></h1>

                    <p class="lead">
                        Events by CK Enterprises UK gives charities, community organisations and small event organisers
                        a straightforward way to sell tickets online. Build your own branded storefront, take payments
                        through your connected Stripe account and scan QR tickets at the door.
                    </p>

                    <div class="hero-actions">
                        @auth
                            <a class="btn btn-primary" href="{{ route('dashboard.home') }}">Go to dashboard</a>
                        @else
                            <a class="btn btn-primary" href="{{ url('/register') }}">Start selling tickets →</a>
                            <a class="btn btn-secondary" href="{{ url('/contact') }}">Talk to us</a>
                        @endauth
                    </div>

                    <div class="hero-proof" aria-label="Key benefits">
                        <div class="hero-proof-item">
                            <div class="hero-proof-icon">£</div>
                            <div>
                                <strong>No monthly subscription</strong>
                                <small>Pay a simple {{ number_format($feePercent, 2) }}% platform fee per ticket.</small>
                            </div>
                        </div>

                        <div class="hero-proof-item">
                            <div class="hero-proof-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                                    <path d="M3 10h18"/>
                                </svg>
                            </div>
                            <div>
                                <strong>Direct Stripe payouts</strong>
                                <small>Payments settle through your connected Stripe account.</small>
                            </div>
                        </div>

                        <div class="hero-proof-item">
                            <div class="hero-proof-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                    <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                                </svg>
                            </div>
                            <div>
                                <strong>Built by CK Enterprises UK</strong>
                                <small>A real UK technology business behind the platform.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hero-visual" aria-label="Events platform preview">
                    <div class="hero-browser">
                        <div class="browser-bar" aria-hidden="true">
                            <span class="browser-dot"></span>
                            <span class="browser-dot"></span>
                            <span class="browser-dot"></span>
                        </div>
                        <img
                            src="{{ asset('images/home/storefront.png') }}"
                            alt="Example public event storefront with ticket selection"
                        >
                    </div>

                    <div class="hero-floating">
                        <strong><span class="accent">Your brand.</span> Your event.</strong>
                        <p>No competing-event marketplace and no buyer account required before somebody can book.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="section soft" id="platform">
            <div class="container">
                <div class="section-heading with-aside">
                    <div>
                        <span class="eyebrow">Powerful tools. Real events.</span>
                        <h2>See the platform in action.</h2>
                    </div>

                    <p>
                        Everything needed to create, sell and manage tickets — from your booking portal to the final
                        scan at the door.
                    </p>
                </div>

                <div class="product-grid">
                    <article class="product-card product-ui">
                        <div class="product-media">
                            <img
                                src="{{ asset('images/home/booking-portal.png') }}"
                                alt="Events booking portal showing ticket type configuration"
                                loading="lazy"
                            >
                        </div>
                        <div class="product-body">
                            <div class="product-title-row">
                                <h3>Inside Booking Portal</h3>
                                <span class="product-arrow" aria-hidden="true">→</span>
                            </div>
                            <p>Create and manage events, ticket types, capacity, sales windows, orders and reporting.</p>
                        </div>
                    </article>

                    <article class="product-card">
                        <div class="product-media">
                            <img
                                src="{{ asset('images/home/gate-scanning.jpg') }}"
                                alt="Event staff scanning a QR ticket at the gate"
                                loading="lazy"
                            >
                        </div>
                        <div class="product-body">
                            <div class="product-title-row">
                                <h3>Scan tickets at the gate</h3>
                                <span class="product-arrow" aria-hidden="true">→</span>
                            </div>
                            <p>Quick QR check-in from a modern phone or tablet, without specialist scanning hardware.</p>
                        </div>
                    </article>

                    <article class="product-card product-ui">
                        <div class="product-media">
                            <img
                                src="{{ asset('images/home/storefront.png') }}"
                                alt="Example online ticket storefront for a public event"
                                loading="lazy"
                            >
                        </div>
                        <div class="product-body">
                            <div class="product-title-row">
                                <h3>Online Storefront Example</h3>
                                <span class="product-arrow" aria-hidden="true">→</span>
                            </div>
                            <p>A clean, mobile-friendly event page that keeps your organisation and your event front and centre.</p>
                        </div>
                    </article>
                </div>

                {{--
                    Expected image assets:
                    public/images/home/booking-portal.png
                    public/images/home/gate-scanning.jpg
                    public/images/home/storefront.png
                --}}
            </div>
        </section>

        <section class="section" id="why">
            <div class="container">
                <div class="section-heading">
                    <span class="eyebrow">Why Events by CK Enterprises UK</span>
                    <h2>Built to be straightforward, accountable and easy to trust.</h2>
                    <p>
                        The platform is designed for organisations that need dependable ticketing without becoming
                        part of a large event marketplace.
                    </p>
                </div>

                <div class="benefit-grid">
                    <article class="benefit">
                        <div class="benefit-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M12 2 3 6v6c0 5 3.8 8.7 9 10 5.2-1.3 9-5 9-10V6l-9-4Z"/>
                                <path d="m9 12 2 2 4-4"/>
                            </svg>
                        </div>
                        <h3>A real company behind it</h3>
                        <p>Events is operated by CK Enterprises Group Limited and supported by CK Enterprises UK.</p>
                    </article>

                    <article class="benefit">
                        <div class="benefit-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <rect x="3" y="5" width="18" height="14" rx="2"/>
                                <path d="M3 10h18"/>
                            </svg>
                        </div>
                        <h3>Your Stripe account</h3>
                        <p>Event income is processed through your connected Stripe account rather than a pooled platform wallet.</p>
                    </article>

                    <article class="benefit">
                        <div class="benefit-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M4 4h16v16H4z"/>
                                <path d="M8 9h8M8 13h5"/>
                            </svg>
                        </div>
                        <h3>Your own storefront</h3>
                        <p>Keep customers focused on your organisation without competing event recommendations or advertising feeds.</p>
                    </article>

                    <article class="benefit">
                        <div class="benefit-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M4 14a8 8 0 0 1 16 0"/>
                                <path d="M4 14v4a2 2 0 0 0 2 2h2v-6H4ZM20 14v4a2 2 0 0 1-2 2h-2v-6h4Z"/>
                            </svg>
                        </div>
                        <h3>UK-based support</h3>
                        <p>Need to discuss your event or ticket setup? Contact the team behind the platform directly.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="section alt" id="how">
            <div class="container">
                <div class="section-heading with-aside">
                    <div>
                        <span class="eyebrow">Get started in minutes</span>
                        <h2>From setup to the front door in four steps.</h2>
                    </div>
                    <p>A simple process for getting your event online, taking bookings and checking guests in.</p>
                </div>

                <div class="steps">
                    <article class="step">
                        <span class="step-number">01</span>
                        <div class="step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                            </svg>
                        </div>
                        <h3>Create your organisation</h3>
                        <p>Add your organisation details, branding and the people who need access.</p>
                    </article>

                    <article class="step">
                        <span class="step-number">02</span>
                        <div class="step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <rect x="3" y="5" width="18" height="14" rx="2"/>
                                <path d="M3 10h18"/>
                            </svg>
                        </div>
                        <h3>Connect Stripe</h3>
                        <p>Link your Stripe account for secure payment processing and direct payouts.</p>
                    </article>

                    <article class="step">
                        <span class="step-number">03</span>
                        <div class="step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <rect x="3" y="4" width="18" height="18" rx="2"/>
                                <path d="M16 2v4M8 2v4M3 10h18"/>
                            </svg>
                        </div>
                        <h3>Publish your event</h3>
                        <p>Create ticket types, set capacity and share your branded storefront.</p>
                    </article>

                    <article class="step">
                        <span class="step-number">04</span>
                        <div class="step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M3 5a2 2 0 0 1 2-2h4v4H3V5ZM15 3h4a2 2 0 0 1 2 2v4h-4V7h-2V3ZM3 15h4v2h2v4H5a2 2 0 0 1-2-2v-4ZM17 15h4v4a2 2 0 0 1-2 2h-4v-4h2v-2Z"/>
                                <path d="M10 10h4v4h-4z"/>
                            </svg>
                        </div>
                        <h3>Scan tickets at the door</h3>
                        <p>Validate QR tickets on a modern phone or tablet and keep arrivals moving.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="section" id="pricing">
            <div class="container pricing-shell">
                <div class="pricing-copy">
                    <span class="eyebrow">Transparent pricing</span>
                    <h2>Know what the platform costs before you sell a ticket.</h2>

                    <div class="price-line">
                        <span>{{ number_format($feePercent, $feePercent == (int) $feePercent ? 0 : 2) }}%</span>
                        platform fee
                    </div>

                    <p class="price-sub">
                        No setup charge and no monthly platform subscription. Stripe's own payment-processing charges are separate.
                    </p>

                    <ul>
                        <li>Absorb the platform fee or include it in the customer-facing ticket price.</li>
                        <li>Customer pricing is shown before checkout.</li>
                        <li>Charities and community organisations can <a class="text-link" href="{{ url('/contact') }}">talk to us</a> about their needs.</li>
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
                                    value="10.00"
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
                                <button type="button" id="mode-absorb" class="active">Organisation absorbs it</button>
                                <button type="button" id="mode-passon">Include it in ticket price</button>
                            </div>
                        </div>
                    </div>

                    <div class="calc-result" aria-live="polite">
                        <div class="calc-row">
                            <span id="result-mode-label">Ticket price</span>
                            <span>£<span id="result-buyer-price">10.00</span></span>
                        </div>

                        <div class="calc-row">
                            <span>Platform fee per ticket</span>
                            <span>£<span id="result-fee-each">{{ number_format(10 * ($feePercent / 100), 2) }}</span></span>
                        </div>

                        <div class="calc-row">
                            <span>Total platform fee</span>
                            <span>£<span id="result-fee-total">{{ number_format((round(10 * ($feePercent / 100), 2)) * 100, 2) }}</span></span>
                        </div>

                        <div class="calc-row total">
                            <span id="result-payout-label">You receive before Stripe fees</span>
                            <span>£<span id="result-payout">{{ number_format(1000 - ((round(10 * ($feePercent / 100), 2)) * 100), 2) }}</span></span>
                        </div>

                        <p class="calc-hint">Estimate excludes Stripe's own payment-processing charges.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="section soft">
            <div class="container">
                <div class="trust-panel">
                    <div class="trust-panel-head">
                        <div>
                            <span class="eyebrow">A platform you can trust</span>
                            <h2>Real people. A real company.</h2>
                        </div>
                        <p>
                            Clear ownership, direct contact and straightforward pricing matter when you are trusting
                            a platform with your event and your customers.
                        </p>
                    </div>

                    <div class="trust-grid">
                        <article class="trust-item">
                            <div class="trust-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M12 2 3 6v6c0 5 3.8 8.7 9 10 5.2-1.3 9-5 9-10V6l-9-4Z"/>
                                </svg>
                            </div>
                            <h3>Operated by CK Enterprises Group Limited</h3>
                            <p>A UK company behind the Events platform, with clear legal and trust information.</p>
                        </article>

                        <article class="trust-item">
                            <div class="trust-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M4 14a8 8 0 0 1 16 0"/>
                                    <path d="M4 14v4a2 2 0 0 0 2 2h2v-6H4ZM20 14v4a2 2 0 0 1-2 2h-2v-6h4Z"/>
                                </svg>
                            </div>
                            <h3>UK-based support</h3>
                            <p>Speak directly to the team if you need help planning, configuring or running your event.</p>
                        </article>

                        <article class="trust-item">
                            <div class="trust-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <line x1="19" y1="5" x2="5" y2="19"/>
                                    <circle cx="6.5" cy="6.5" r="2.5"/>
                                    <circle cx="17.5" cy="17.5" r="2.5"/>
                                </svg>
                            </div>
                            <h3>Transparent {{ number_format($feePercent, 2) }}% pricing</h3>
                            <p>No monthly platform subscription and no separate fixed CK Enterprises UK charge per ticket.</p>
                        </article>
                    </div>
                </div>
            </div>
        </section>

        <section class="closing">
            <div class="container closing-inner">
                <div>
                    <h2>Planning an event?</h2>
                    <p>
                        Start building it now, or speak to CK Enterprises UK about your organisation, expected ticket
                        volume or how Events could fit your requirements.
                    </p>
                </div>

                <div class="closing-actions">
                    @auth
                        <a class="btn btn-primary" href="{{ route('dashboard.home') }}">Go to dashboard</a>
                    @else
                        <a class="btn btn-primary" href="{{ url('/register') }}">Get started</a>
                        <a class="btn btn-secondary" href="{{ url('/contact') }}">Talk to us</a>
                    @endauth
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <a class="brand" href="{{ url('/') }}" aria-label="Events by CK Enterprises UK home">
                    <img class="brand-mark" src="{{ asset('images/favicon.png') }}" alt="" aria-hidden="true">
                    <span class="brand-copy">
                        <span class="brand-events">Events</span>
                        <span class="brand-by">by</span>
                        <span class="brand-ck">CK Enterprises UK</span>
                    </span>
                </a>

                <div class="footer-links">
                    <a href="#why">Why us</a>
                    <a href="#pricing">Pricing</a>
                    <a href="#how">How it works</a>
                    <a href="{{ route('trust.index') }}">Trust Centre</a>
                    <a href="{{ url('/contact') }}">Contact</a>
                    <a href="https://ckenterprises.co.uk/" target="_blank" rel="noopener">CK Enterprises UK</a>
                    @guest
                        <a href="{{ url('/login') }}">Log in</a>
                    @endguest
                </div>
            </div>

            <div class="footer-copy">
                <div>&copy; {{ date('Y') }} CK Enterprises UK. All rights reserved.</div>
                <div class="footer-company">
                    Events by CK Enterprises UK is operated by CK Enterprises Group Limited.
                </div>
            </div>
        </div>
    </footer>

    <script>
        (function () {
            /* Mobile navigation */
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
                    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    toggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
                    document.body.classList.toggle('menu-open', isOpen);
                });

                menu.querySelectorAll('a').forEach(function (link) {
                    link.addEventListener('click', closeMenu);
                });

                window.addEventListener('resize', function () {
                    if (window.innerWidth > 1020) closeMenu();
                });

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') closeMenu();
                });
            }

            /* Pricing calculator */
            var root = document.querySelector('.calc');
            if (!root) return;

            var feePct = Math.max(0, parseFloat(root.getAttribute('data-fee')) || 0);
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
                var price = Math.max(0, parseFloat(priceEl.value) || 0);
                var qty = Math.max(0, parseInt(qtyEl.value, 10) || 0);
                var feeEach = Math.round(price * (feePct / 100) * 100) / 100;
                var feeTotal = Math.round(feeEach * qty * 100) / 100;
                var buyerPrice;
                var payout;

                if (passOn) {
                    buyerPrice = price + feeEach;
                    payout = Math.round(price * qty * 100) / 100;
                    outModeLabel.textContent = 'Ticket price including platform fee';
                    outPayoutLabel.textContent = 'You receive before Stripe fees';
                } else {
                    buyerPrice = price;
                    payout = Math.round((price * qty - feeTotal) * 100) / 100;
                    outModeLabel.textContent = 'Ticket price';
                    outPayoutLabel.textContent = 'You receive before Stripe fees';
                }

                outBuyerPrice.textContent = money(buyerPrice);
                outFeeEach.textContent = money(feeEach);
                outFeeTotal.textContent = money(feeTotal);
                outPayout.textContent = money(Math.max(0, payout));
            }

            function setMode(isPassOn) {
                passOn = isPassOn;
                absorbBtn.classList.toggle('active', !isPassOn);
                passOnBtn.classList.toggle('active', isPassOn);
                recalc();
            }

            [priceEl, qtyEl].forEach(function (element) {
                element.addEventListener('input', recalc);
            });

            absorbBtn.addEventListener('click', function () { setMode(false); });
            passOnBtn.addEventListener('click', function () { setMode(true); });
            recalc();
        })();
    </script>
</body>
</html>
