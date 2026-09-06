<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name', 'Event Ticketing Platform'))</title>
    <meta name="description" content="Sell tickets from your own branded storefront. Direct Stripe payouts, phone-browser QR check-in and team roles — built for charities and event organisers.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f1425;
            --navy-2: #1b1f2e;
            --purple: #674df3;
            --purple-dark: #5238d6;
            --teal: #30f0b6;
            --ink: #1b1f2e;
            --body: #454a5a;
            --muted: #838694;
            --line: #e6e7ec;
            --surface: #ffffff;
            --surface-2: #f6f7f9;
            --heading-font: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --body-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: var(--body-font);
            color: var(--body);
            background: var(--surface);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3, h4 { font-family: var(--heading-font); color: var(--ink); line-height: 1.25; margin: 0; }
        a { color: var(--purple); text-decoration: none; }
        p { margin: 0 0 1rem; }
        .container { width: 100%; max-width: 1080px; margin: 0 auto; padding: 0 1.5rem; }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            padding: .75rem 1.5rem; border-radius: 6px; font-weight: 600; font-size: 1rem;
            font-family: var(--body-font); cursor: pointer; border: 1px solid transparent;
            transition: background .15s ease, color .15s ease, border-color .15s ease;
        }
        .btn-primary { background: var(--purple); color: #fff; }
        .btn-primary:hover { background: var(--purple-dark); color: #fff; }
        .btn-light { background: #fff; color: var(--navy); }
        .btn-light:hover { background: #eef0f6; color: var(--navy); }
        .btn-outline-light { background: transparent; color: #fff; border-color: rgba(255,255,255,.4); }
        .btn-outline-light:hover { border-color: #fff; color: #fff; }
        .btn-outline { background: transparent; color: var(--purple); border-color: var(--purple); }
        .btn-outline:hover { background: var(--purple); color: #fff; }

        /* Header */
        .site-header {
            position: sticky; top: 0; z-index: 50;
            background: var(--navy);
            border-bottom: 1px solid rgba(255,255,255,.07);
        }
        .site-header .container { display: flex; align-items: center; justify-content: space-between; height: 68px; }
        .logo { display: flex; align-items: center; gap: .6rem; font-family: var(--heading-font); font-weight: 700; font-size: 1.15rem; color: #fff; }
        .logo .mark { width: 32px; height: 32px; border-radius: 7px; background: var(--purple); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: .85rem; }
        .nav { display: flex; align-items: center; gap: 1.75rem; }
        .nav a.link { color: #c8ccdb; font-weight: 500; font-size: .95rem; }
        .nav a.link:hover { color: #fff; }

        /* Hero */
        .hero {
            background: var(--navy);
            color: #e9ebf2;
            padding: 4.5rem 0 5rem;
            border-bottom: 3px solid var(--purple);
        }
        .hero .grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 3.5rem; align-items: center; }
        .eyebrow { display: inline-block; font-size: .8rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--teal); margin-bottom: 1.1rem; }
        .hero h1 { color: #fff; font-size: 2.85rem; font-weight: 700; margin-bottom: 1.1rem; }
        .hero p.lead { font-size: 1.15rem; color: #c1c5d4; max-width: 540px; }
        .hero .cta { display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1.75rem; }
        .hero .reassure { margin: 1.4rem 0 0; font-size: .9rem; color: #8b90a5; }

        /* Hero side panel — flat, no glass */
        .hero-panel { background: #fff; border-radius: 10px; padding: 1.5rem; box-shadow: 0 20px 40px rgba(0,0,0,.25); }
        .hero-panel .row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .9rem 0; border-bottom: 1px solid var(--line); }
        .hero-panel .row:first-child { padding-top: 0; }
        .hero-panel .row:last-child { padding-bottom: 0; border-bottom: none; }
        .hero-panel .row h4 { font-size: .98rem; color: var(--ink); margin: 0 0 .1rem; }
        .hero-panel .row span { font-size: .84rem; color: var(--muted); }
        .hero-panel .row .amt { font-family: var(--heading-font); font-weight: 700; font-size: 1.1rem; color: var(--purple); white-space: nowrap; }

        /* Sections */
        section.block { padding: 4.5rem 0; }
        section.block.alt { background: var(--surface-2); }
        .section-head { max-width: 640px; margin: 0 auto 3rem; text-align: center; }
        .section-head .kicker { color: var(--purple); font-weight: 600; text-transform: uppercase; letter-spacing: .08em; font-size: .8rem; }
        .section-head h2 { font-size: 2rem; margin: .6rem 0 .7rem; }
        .section-head p { font-size: 1.08rem; color: var(--muted); margin: 0; }

        /* Features — flat cards */
        .features { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem; }
        .feature-card { background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 1.75rem; }
        .feature-card .icon { width: 40px; height: 40px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem; background: var(--navy); color: var(--teal); }
        .feature-card h3 { font-size: 1.12rem; margin-bottom: .5rem; }
        .feature-card p { margin: 0; color: var(--muted); font-size: .96rem; }

        /* How it works */
        .steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.25rem; counter-reset: step; }
        .step { padding: 1.75rem 1.5rem; background: var(--surface); border: 1px solid var(--line); border-radius: 8px; }
        .step .num { counter-increment: step; width: 34px; height: 34px; border-radius: 6px; background: var(--surface-2); color: var(--purple); border: 1px solid var(--line); font-family: var(--heading-font); font-weight: 700; display: inline-flex; align-items: center; justify-content: center; margin-bottom: .9rem; }
        .step .num::before { content: counter(step); }
        .step h3 { font-size: 1.05rem; margin-bottom: .4rem; }
        .step p { margin: 0; color: var(--muted); font-size: .94rem; }

        /* Calculator */
        .calc { display: grid; grid-template-columns: 1fr 1fr; gap: 2.5rem; align-items: start; background: var(--surface); border: 1px solid var(--line); border-radius: 10px; padding: 2.5rem; }
        .calc .field { margin-bottom: 1.5rem; }
        .calc label { display: block; font-weight: 600; color: var(--ink); margin-bottom: .5rem; font-size: .95rem; }
        .calc .input-wrap { position: relative; }
        .calc .input-wrap .prefix { position: absolute; left: .9rem; top: 50%; transform: translateY(-50%); color: var(--muted); font-weight: 600; }
        .calc input[type="number"] { width: 100%; padding: .8rem 1rem .8rem 1.9rem; border: 1px solid #d5d7e0; border-radius: 6px; font-size: 1.05rem; font-family: var(--body-font); }
        .calc input.no-prefix { padding-left: 1rem; }
        .calc input:focus { outline: none; border-color: var(--purple); box-shadow: 0 0 0 3px rgba(103,77,243,.12); }
        .calc input[type="range"] { width: 100%; accent-color: var(--purple); }
        .calc .range-head { display: flex; justify-content: space-between; align-items: baseline; }
        .calc .range-head .val { font-family: var(--heading-font); font-weight: 700; color: var(--purple); }
        .toggle { display: inline-flex; border: 1px solid #d5d7e0; border-radius: 6px; overflow: hidden; }
        .toggle button { border: none; background: #fff; padding: .55rem 1rem; font-family: var(--body-font); font-size: .9rem; font-weight: 600; color: var(--muted); cursor: pointer; }
        .toggle button.active { background: var(--purple); color: #fff; }
        .calc .result { background: var(--navy); color: #fff; border-radius: 10px; padding: 2rem; }
        .calc .result h3 { color: #fff; font-size: 1.1rem; margin-bottom: 1.25rem; }
        .calc .result .line { display: flex; justify-content: space-between; align-items: baseline; padding: .65rem 0; border-bottom: 1px solid rgba(255,255,255,.1); font-size: .98rem; color: #c1c5d4; }
        .calc .result .line span.v { color: #fff; font-weight: 600; }
        .calc .result .line.total { border-bottom: none; padding-top: 1.1rem; font-size: 1.05rem; color: #fff; }
        .calc .result .line.total span.v { font-family: var(--heading-font); font-size: 1.5rem; color: var(--teal); }
        .calc .result .hint { margin: 1rem 0 0; font-size: .82rem; color: #8b90a5; }
        .calc-note { max-width: 720px; margin: 1.75rem auto 0; text-align: center; font-size: .95rem; color: var(--muted); }
        .calc-note strong { color: var(--ink); }

        /* CTA band */
        .cta-band { background: var(--navy); color: #fff; border-radius: 12px; padding: 3rem 2.5rem; text-align: center; border-top: 3px solid var(--purple); }
        .cta-band h2 { color: #fff; font-size: 1.9rem; }
        .cta-band p { color: #c1c5d4; max-width: 520px; margin: .9rem auto 1.75rem; font-size: 1.05rem; }
        .cta-band .cta { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }

        /* Footer */
        .site-footer { background: var(--navy); color: #8b90a5; padding: 2.75rem 0 2rem; }
        .site-footer .grid { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.25rem; padding-bottom: 1.75rem; border-bottom: 1px solid rgba(255,255,255,.08); }
        .site-footer .grid .logo { color: #fff; }
        .site-footer .links { display: flex; gap: 1.5rem; flex-wrap: wrap; }
        .site-footer .links a { color: #c1c5d4; font-size: .94rem; }
        .site-footer .links a:hover { color: #fff; }
        .site-footer .copy { padding-top: 1.5rem; font-size: .85rem; }
        .site-footer .copy a { color: #c1c5d4; }
        .site-footer .copy a:hover { color: #fff; }

        @media (max-width: 900px) {
            .hero .grid { grid-template-columns: 1fr; gap: 2.5rem; }
            .hero h1 { font-size: 2.3rem; }
            .features { grid-template-columns: 1fr; }
            .steps { grid-template-columns: 1fr 1fr; }
            .calc { grid-template-columns: 1fr; gap: 2rem; padding: 1.75rem; }
            .nav a.link { display: none; }
        }
        @media (max-width: 520px) {
            .steps { grid-template-columns: 1fr; }
            .hero h1 { font-size: 2rem; }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a class="logo" href="{{ url('/') }}">
                <span class="mark">CK</span>
                <span>{{ config('app.name', 'Event Ticketing') }}</span>
            </a>
            <nav class="nav">
                <a class="link" href="#features">Features</a>
                <a class="link" href="#how">How it works</a>
                <a class="link" href="#pricing">Pricing</a>
                @auth
                    <form method="POST" action="{{ url('/logout') }}" style="margin:0">
                        @csrf
                        <button type="submit" class="btn btn-outline-light">Log out</button>
                    </form>
                @else
                    <a class="link" href="{{ url('/login') }}">Log in</a>
                    <a class="btn btn-primary" href="{{ url('/register') }}">Get started</a>
                @endauth
            </nav>
        </div>
    </header>

    <!-- Hero -->
    <section class="hero">
        <div class="container grid">
            <div>
                <span class="eyebrow">For charities &amp; event organisers</span>
                <h1>Sell tickets from your own branded storefront</h1>
                <p class="lead">
                    Take card payments straight into your own Stripe account, check attendees
                    in with a phone-browser QR scanner, and stay in full control of your event.
                    No middleman holding your money.
                </p>
                <div class="cta">
                    <a class="btn btn-primary" href="{{ url('/register') }}">Get started free</a>
                    <a class="btn btn-outline-light" href="#pricing">See our pricing</a>
                </div>
                <p class="reassure">No setup fees &middot; Direct Stripe payouts &middot; Fees negotiable for eligible charities</p>
            </div>
            <div class="hero-panel">
                <div class="row">
                    <div>
                        <h4>Summer Fundraiser Gala</h4>
                        <span>Sat 12 July &middot; General admission</span>
                    </div>
                    <span class="amt">£25.00</span>
                </div>
                <div class="row">
                    <div>
                        <h4>Tickets sold</h4>
                        <span>Checked in at the door via QR</span>
                    </div>
                    <span class="amt">142</span>
                </div>
                <div class="row">
                    <div>
                        <h4>Paid out to you</h4>
                        <span>Settled directly through Stripe</span>
                    </div>
                    <span class="amt" style="color:#1d9b6c">£3,372.50</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section class="block" id="features">
        <div class="container">
            <div class="section-head">
                <span class="kicker">What you get</span>
                <h2>One platform, from first sale to the front door</h2>
                <p>Secure, straightforward and built around how your organisation actually works.</p>
            </div>
            <div class="features">
                <div class="feature-card">
                    <div class="icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
                    </div>
                    <h3>Your own storefront</h3>
                    <p>A branded storefront with your logo, colours and terms, on your own URL.</p>
                </div>
                <div class="feature-card">
                    <div class="icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    </div>
                    <h3>Direct payouts</h3>
                    <p>Connect Stripe and take card payments that settle straight to you.</p>
                </div>
                <div class="feature-card">
                    <div class="icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>
                    </div>
                    <h3>Fast door check-in</h3>
                    <p>Scan tickets from any phone browser. QR codes verify instantly and only work once.</p>
                </div>
                <div class="feature-card">
                    <div class="icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <h3>Roles for your team</h3>
                    <p>Invite admins, accountants and scanners with the right access. You stay the Owner.</p>
                </div>
                <div class="feature-card">
                    <div class="icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                    <h3>Clear reporting</h3>
                    <p>See sales, attendance and payouts at a glance so you know how the event is doing.</p>
                </div>
                <div class="feature-card">
                    <div class="icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <h3>Secure &amp; GDPR-aware</h3>
                    <p>Consent capture and secure hosting built in, so supporter data stays protected.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- How it works -->
    <section class="block alt" id="how">
        <div class="container">
            <div class="section-head">
                <span class="kicker">How it works</span>
                <h2>Up and selling in four steps</h2>
                <p>No enterprise complexity. Get your first event live in minutes.</p>
            </div>
            <div class="steps">
                <div class="step">
                    <div class="num"></div>
                    <h3>Create your account</h3>
                    <p>Sign up free and set up your branded storefront in a couple of clicks.</p>
                </div>
                <div class="step">
                    <div class="num"></div>
                    <h3>Connect Stripe</h3>
                    <p>Link your Stripe account so payments settle straight to your organisation.</p>
                </div>
                <div class="step">
                    <div class="num"></div>
                    <h3>Publish your event</h3>
                    <p>Add ticket types, set capacity and share your storefront link.</p>
                </div>
                <div class="step">
                    <div class="num"></div>
                    <h3>Scan &amp; welcome</h3>
                    <p>Check attendees in from any phone and track the door in real time.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing calculator -->
    <section class="block" id="pricing">
        <div class="container">
            <div class="section-head">
                <span class="kicker">Simple pricing</span>
                <h2>Work out your platform fee</h2>
                <p>A flat {{ number_format((float) \App\Models\PlatformSetting::DEFAULT_GLOBAL_FEE_PERCENT, 1) }}% platform fee per ticket. No monthly cost, no setup fee. Try the numbers below.</p>
            </div>

            <div class="calc"
                 data-default-fee="{{ (float) \App\Models\PlatformSetting::DEFAULT_GLOBAL_FEE_PERCENT }}">
                <div class="calc-inputs">
                    <div class="field">
                        <label for="calc-price">Ticket price</label>
                        <div class="input-wrap">
                            <span class="prefix">£</span>
                            <input type="number" id="calc-price" min="0" step="0.50" value="25.00" inputmode="decimal">
                        </div>
                    </div>
                    <div class="field">
                        <label for="calc-qty">Tickets sold</label>
                        <div class="input-wrap">
                            <input type="number" id="calc-qty" class="no-prefix" min="1" step="1" value="100" inputmode="numeric">
                        </div>
                    </div>
                    <div class="field">
                        <div class="range-head">
                            <label for="calc-fee">Platform fee</label>
                            <span class="val"><span id="calc-fee-val">5.0</span>%</span>
                        </div>
                        <input type="range" id="calc-fee" min="0" max="5" step="0.1" value="5">
                    </div>
                    <div class="field">
                        <label>Who pays the fee?</label>
                        <div class="toggle" role="group" aria-label="Fee handling">
                            <button type="button" id="mode-absorb" class="active">We absorb it</button>
                            <button type="button" id="mode-passon">Attendee pays</button>
                        </div>
                    </div>
                </div>

                <div class="result" aria-live="polite">
                    <h3>Your estimate</h3>
                    <div class="line">
                        <span id="result-mode-label">Ticket price</span>
                        <span class="v">£<span id="result-buyer-price">25.00</span></span>
                    </div>
                    <div class="line">
                        <span>Platform fee per ticket</span>
                        <span class="v">£<span id="result-fee-each">1.25</span></span>
                    </div>
                    <div class="line">
                        <span>Total platform fee</span>
                        <span class="v">£<span id="result-fee-total">125.00</span></span>
                    </div>
                    <div class="line total">
                        <span id="result-payout-label">You receive</span>
                        <span class="v">£<span id="result-payout">2,375.00</span></span>
                    </div>
                    <p class="hint">Estimate excludes Stripe's own card processing charges, which Stripe deducts separately.</p>
                </div>
            </div>

            <p class="calc-note">
                <strong>Fees are negotiable for eligible charities and community organisations.</strong>
                If you run regular events or high volumes, <a href="https://ckenterprises.co.uk/#contact" target="_blank" rel="noopener">talk to us</a>
                about a reduced rate that works for your organisation.
            </p>
        </div>
    </section>

    <!-- CTA band -->
    <section class="block alt">
        <div class="container">
            <div class="cta-band">
                <h2>Ready to sell your first ticket?</h2>
                <p>Set up your branded storefront today, with clear advice, transparent pricing and support you can depend on.</p>
                <div class="cta">
                    <a class="btn btn-light" href="{{ url('/register') }}">Get started free</a>
                    <a class="btn btn-outline-light" href="{{ url('/login') }}">Log in</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="site-footer">
        <div class="container">
            <div class="grid">
                <a class="logo" href="{{ url('/') }}">
                    <span class="mark">CK</span>
                    <span>{{ config('app.name', 'Event Ticketing') }}</span>
                </a>
                <div class="links">
                    <a href="#features">Features</a>
                    <a href="#how">How it works</a>
                    <a href="#pricing">Pricing</a>
                    <a href="{{ url('/login') }}">Log in</a>
                    <a href="{{ url('/register') }}">Get started</a>
                </div>
            </div>
            <div class="copy">
                &copy; {{ date('Y') }} <a href="https://ckenterprises.co.uk/" target="_blank" rel="noopener">CK Enterprises UK</a> Product. All rights reserved.
            </div>
        </div>
    </footer>

    <script>
        (function () {
            var root = document.querySelector('.calc');
            if (!root) return;

            var priceEl = document.getElementById('calc-price');
            var qtyEl = document.getElementById('calc-qty');
            var feeEl = document.getElementById('calc-fee');
            var feeValEl = document.getElementById('calc-fee-val');
            var absorbBtn = document.getElementById('mode-absorb');
            var passOnBtn = document.getElementById('mode-passon');

            var outModeLabel = document.getElementById('result-mode-label');
            var outBuyerPrice = document.getElementById('result-buyer-price');
            var outFeeEach = document.getElementById('result-fee-each');
            var outFeeTotal = document.getElementById('result-fee-total');
            var outPayoutLabel = document.getElementById('result-payout-label');
            var outPayout = document.getElementById('result-payout');

            var passOn = false;

            function money(n) {
                return n.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            function recalc() {
                var price = Math.max(0, parseFloat(priceEl.value) || 0);
                var qty = Math.max(0, parseInt(qtyEl.value, 10) || 0);
                var feePct = Math.max(0, parseFloat(feeEl.value) || 0);
                feeValEl.textContent = feePct.toFixed(1);

                // Fee per ticket, rounded to the penny (matches server half-up rounding closely).
                var feeEach = Math.round(price * (feePct / 100) * 100) / 100;
                var feeTotal = Math.round(feeEach * qty * 100) / 100;

                var buyerPrice, payout;
                if (passOn) {
                    // Attendee pays the fee on top; organiser receives the full ticket price.
                    buyerPrice = price + feeEach;
                    payout = Math.round(price * qty * 100) / 100;
                    outModeLabel.textContent = 'Attendee pays (incl. fee)';
                    outPayoutLabel.textContent = 'You receive';
                } else {
                    // Organiser absorbs the fee out of the ticket price.
                    buyerPrice = price;
                    payout = Math.round((price * qty - feeTotal) * 100) / 100;
                    outModeLabel.textContent = 'Ticket price';
                    outPayoutLabel.textContent = 'You receive';
                }

                outBuyerPrice.textContent = money(buyerPrice);
                outFeeEach.textContent = money(feeEach);
                outFeeTotal.textContent = money(feeTotal);
                outPayout.textContent = money(payout < 0 ? 0 : payout);
            }

            function setMode(isPassOn) {
                passOn = isPassOn;
                absorbBtn.classList.toggle('active', !isPassOn);
                passOnBtn.classList.toggle('active', isPassOn);
                recalc();
            }

            [priceEl, qtyEl, feeEl].forEach(function (el) {
                el.addEventListener('input', recalc);
            });
            absorbBtn.addEventListener('click', function () { setMode(false); });
            passOnBtn.addEventListener('click', function () { setMode(true); });

            recalc();
        })();
    </script>
</body>
</html>
