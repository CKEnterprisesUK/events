<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name', 'Event Ticketing Platform'))</title>
    <meta name="description" content="Sell tickets from your own branded storefront. Direct Stripe payouts, fast QR door check-in, and team roles — built for charities and event organisers.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0f1425;
            --navy-2: #1b1f2e;
            --navy-3: #232a42;
            --purple: #674df3;
            --purple-dark: #5238d6;
            --teal: #30f0b6;
            --ink: #1b1f2e;
            --body: #4a4f60;
            --muted: #838694;
            --line: #e8e8ea;
            --surface: #ffffff;
            --surface-2: #f7f7f9;
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
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3 { font-family: var(--heading-font); color: var(--ink); line-height: 1.2; margin: 0; }
        a { color: var(--purple); text-decoration: none; }
        .container { width: 100%; max-width: 1140px; margin: 0 auto; padding: 0 1.5rem; }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            padding: .85rem 1.6rem; border-radius: 999px; font-weight: 600; font-size: 1rem;
            font-family: var(--body-font); cursor: pointer; border: 2px solid transparent;
            transition: transform .15s ease, box-shadow .15s ease, background .15s ease, color .15s ease;
        }
        .btn-primary { background: var(--purple); color: #fff; box-shadow: 0 10px 24px rgba(103,77,243,.35); }
        .btn-primary:hover { background: var(--purple-dark); transform: translateY(-2px); color: #fff; }
        .btn-teal { background: var(--teal); color: var(--navy); box-shadow: 0 10px 24px rgba(48,240,182,.3); }
        .btn-teal:hover { transform: translateY(-2px); color: var(--navy); }
        .btn-ghost { background: transparent; color: #fff; border-color: rgba(255,255,255,.35); }
        .btn-ghost:hover { border-color: #fff; background: rgba(255,255,255,.08); color: #fff; }
        .btn-outline { background: transparent; color: var(--purple); border-color: var(--purple); }
        .btn-outline:hover { background: var(--purple); color: #fff; }

        /* Header */
        .site-header {
            position: sticky; top: 0; z-index: 50;
            background: rgba(15,20,37,.9); backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,.08);
        }
        .site-header .container { display: flex; align-items: center; justify-content: space-between; height: 72px; }
        .logo { display: flex; align-items: center; gap: .6rem; font-family: var(--heading-font); font-weight: 700; font-size: 1.2rem; color: #fff; }
        .logo .mark { width: 34px; height: 34px; border-radius: 9px; background: linear-gradient(135deg, var(--purple), var(--teal)); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; }
        .nav { display: flex; align-items: center; gap: 1.75rem; }
        .nav a.link { color: #c8ccdb; font-weight: 500; font-size: .95rem; }
        .nav a.link:hover { color: #fff; }
        .nav-toggle { display: none; }

        /* Hero */
        .hero {
            position: relative; overflow: hidden;
            background:
                radial-gradient(900px 500px at 85% -10%, rgba(103,77,243,.45), transparent 60%),
                radial-gradient(700px 500px at 10% 110%, rgba(48,240,182,.22), transparent 55%),
                linear-gradient(180deg, var(--navy), var(--navy-2));
            color: #eef0f6;
            padding: 5.5rem 0 6rem;
        }
        .hero .grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 3rem; align-items: center; }
        .eyebrow { display: inline-flex; align-items: center; gap: .5rem; font-size: .8rem; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--teal); background: rgba(48,240,182,.1); border: 1px solid rgba(48,240,182,.25); padding: .35rem .8rem; border-radius: 999px; margin-bottom: 1.4rem; }
        .hero h1 { color: #fff; font-size: 3.1rem; font-weight: 800; margin-bottom: 1.2rem; }
        .hero h1 .accent { background: linear-gradient(90deg, var(--teal), #8ff5d3); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; }
        .hero p.lead { font-size: 1.2rem; color: #c8ccdb; max-width: 560px; margin: 0 0 2rem; }
        .hero .cta { display: flex; gap: 1rem; flex-wrap: wrap; }
        .hero .reassure { margin-top: 1.5rem; font-size: .9rem; color: #9aa0b5; }

        /* Hero visual card */
        .hero-card {
            background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12);
            border-radius: 20px; padding: 1.5rem; backdrop-filter: blur(6px);
            box-shadow: 0 30px 60px rgba(0,0,0,.35);
        }
        .hero-card .ticket {
            background: #fff; color: var(--ink); border-radius: 14px; padding: 1.25rem 1.4rem;
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            box-shadow: 0 12px 30px rgba(0,0,0,.15); margin-bottom: 1rem;
        }
        .hero-card .ticket:last-child { margin-bottom: 0; }
        .hero-card .ticket .info h4 { font-family: var(--heading-font); font-size: 1rem; margin: 0 0 .15rem; color: var(--ink); }
        .hero-card .ticket .info span { font-size: .85rem; color: var(--muted); }
        .hero-card .ticket .price { font-family: var(--heading-font); font-weight: 700; color: var(--purple); font-size: 1.15rem; }
        .hero-card .qr { width: 46px; height: 46px; border-radius: 10px; background:
            conic-gradient(from 45deg, #0f1425 25%, #fff 0 50%, #0f1425 0 75%, #fff 0); background-size: 12px 12px; border: 3px solid var(--navy); }

        /* Section shells */
        section.block { padding: 5rem 0; }
        section.block.alt { background: var(--surface-2); }
        .section-head { text-align: center; max-width: 680px; margin: 0 auto 3rem; }
        .section-head .kicker { color: var(--purple); font-weight: 700; text-transform: uppercase; letter-spacing: .1em; font-size: .8rem; }
        .section-head h2 { font-size: 2.2rem; margin: .6rem 0 .8rem; }
        .section-head p { font-size: 1.1rem; color: var(--muted); margin: 0; }

        /* Features */
        .features { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
        .feature-card { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 2rem 1.75rem; transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
        .feature-card:hover { transform: translateY(-4px); box-shadow: 0 18px 40px rgba(27,31,46,.1); border-color: transparent; }
        .feature-card .icon { width: 52px; height: 52px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1.1rem; background: linear-gradient(135deg, rgba(103,77,243,.14), rgba(48,240,182,.14)); font-size: 1.5rem; }
        .feature-card h3 { font-size: 1.2rem; margin-bottom: .5rem; }
        .feature-card p { margin: 0; color: var(--muted); font-size: .97rem; }

        /* How it works */
        .steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; counter-reset: step; }
        .step { position: relative; padding: 2rem 1.5rem; background: var(--surface); border: 1px solid var(--line); border-radius: 16px; }
        .step .num { counter-increment: step; width: 40px; height: 40px; border-radius: 50%; background: var(--purple); color: #fff; font-family: var(--heading-font); font-weight: 700; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem; }
        .step .num::before { content: counter(step); }
        .step h3 { font-size: 1.1rem; margin-bottom: .4rem; }
        .step p { margin: 0; color: var(--muted); font-size: .95rem; }

        /* Testimonials */
        .quotes { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem; }
        .quote { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 1.75rem; }
        .quote .stars { color: #fcb900; margin-bottom: .75rem; letter-spacing: 2px; }
        .quote p { font-size: 1.02rem; color: var(--ink); margin: 0 0 1rem; }
        .quote .who { font-size: .9rem; color: var(--muted); }
        .quote .who strong { color: var(--ink); font-family: var(--heading-font); }

        /* CTA band */
        .cta-band { background: linear-gradient(120deg, var(--navy), var(--navy-2)); color: #fff; border-radius: 24px; padding: 3.5rem 2.5rem; text-align: center; position: relative; overflow: hidden; }
        .cta-band::after { content: ''; position: absolute; inset: 0; background: radial-gradient(600px 300px at 90% 0%, rgba(103,77,243,.4), transparent 60%), radial-gradient(500px 300px at 0% 100%, rgba(48,240,182,.25), transparent 60%); pointer-events: none; }
        .cta-band h2 { color: #fff; font-size: 2.2rem; position: relative; }
        .cta-band p { color: #c8ccdb; max-width: 560px; margin: 1rem auto 2rem; position: relative; font-size: 1.1rem; }
        .cta-band .cta { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; position: relative; }

        /* Footer */
        .site-footer { background: var(--navy); color: #9aa0b5; padding: 3rem 0 2rem; }
        .site-footer .grid { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.5rem; padding-bottom: 2rem; border-bottom: 1px solid rgba(255,255,255,.08); }
        .site-footer .grid .logo { color: #fff; }
        .site-footer .links { display: flex; gap: 1.5rem; flex-wrap: wrap; }
        .site-footer .links a { color: #c8ccdb; font-size: .95rem; }
        .site-footer .links a:hover { color: #fff; }
        .site-footer .copy { padding-top: 1.5rem; font-size: .85rem; }

        @media (max-width: 900px) {
            .hero .grid { grid-template-columns: 1fr; }
            .hero h1 { font-size: 2.4rem; }
            .features { grid-template-columns: 1fr; }
            .steps { grid-template-columns: 1fr 1fr; }
            .quotes { grid-template-columns: 1fr; }
            .nav .link { display: none; }
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
                <a class="link" href="#testimonials">Reviews</a>
                @auth
                    <form method="POST" action="{{ url('/logout') }}" style="margin:0">
                        @csrf
                        <button type="submit" class="btn btn-ghost">Dashboard</button>
                    </form>
                @else
                    <a class="link" href="{{ url('/login') }}">Log in</a>
                    <a class="btn btn-teal" href="{{ url('/register') }}">Get started</a>
                @endauth
            </nav>
        </div>
    </header>

    <!-- Hero -->
    <section class="hero">
        <div class="container grid">
            <div>
                <span class="eyebrow">Built for charities &amp; event organisers</span>
                <h1>Sell tickets from your own <span class="accent">branded storefront</span></h1>
                <p class="lead">
                    Take card payments straight into your own Stripe account, check attendees in
                    with a phone-browser QR scanner, and keep full control of your event — no
                    middleman holding your money.
                </p>
                <div class="cta">
                    <a class="btn btn-teal" href="{{ url('/register') }}">Get started free</a>
                    <a class="btn btn-ghost" href="{{ url('/login') }}">Log in to your dashboard</a>
                </div>
                <p class="reassure">No setup fees · Direct payouts · Cancel anytime</p>
            </div>
            <div class="hero-card">
                <div class="ticket">
                    <div class="info">
                        <h4>Charity Summer Gala</h4>
                        <span>Sat 12 July · General Admission</span>
                    </div>
                    <span class="price">£25</span>
                </div>
                <div class="ticket">
                    <div class="info">
                        <h4>Scan at the door</h4>
                        <span>Verified &amp; single-use QR</span>
                    </div>
                    <span class="qr" aria-hidden="true"></span>
                </div>
                <div class="ticket">
                    <div class="info">
                        <h4>Paid out to you</h4>
                        <span>Settled directly via Stripe</span>
                    </div>
                    <span class="price" style="color:var(--teal)">£1,240</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section class="block" id="features">
        <div class="container">
            <div class="section-head">
                <span class="kicker">Everything you need</span>
                <h2>One platform, from first sale to the front door</h2>
                <p>Simple, secure and straightforward to manage — the way your organisation actually works.</p>
            </div>
            <div class="features">
                <div class="feature-card">
                    <div class="icon">🎨</div>
                    <h3>Your own storefront</h3>
                    <p>Every organisation gets a branded storefront with your logo, colours and terms — on your own URL.</p>
                </div>
                <div class="feature-card">
                    <div class="icon">💳</div>
                    <h3>Direct payouts</h3>
                    <p>Connect your Stripe account and take card payments that settle directly to you, no middleman holding your money.</p>
                </div>
                <div class="feature-card">
                    <div class="icon">📱</div>
                    <h3>Fast door check-in</h3>
                    <p>Scan tickets from any phone browser. QR codes are verified instantly and can only be used once.</p>
                </div>
                <div class="feature-card">
                    <div class="icon">👥</div>
                    <h3>Roles for your team</h3>
                    <p>Invite admins, accountants and scanners with the right access. You stay in control as the account Owner.</p>
                </div>
                <div class="feature-card">
                    <div class="icon">📊</div>
                    <h3>Clear reporting</h3>
                    <p>See sales, attendance and payouts at a glance so you always know how your event is performing.</p>
                </div>
                <div class="feature-card">
                    <div class="icon">🔒</div>
                    <h3>Secure &amp; compliant</h3>
                    <p>GDPR-aware consent capture and secure hosting built in, so supporter data stays protected.</p>
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

    <!-- Testimonials -->
    <section class="block" id="testimonials">
        <div class="container">
            <div class="section-head">
                <span class="kicker">What people say</span>
                <h2>Trusted by charities and small businesses</h2>
                <p>For nearly 10 years CK Enterprises has supported organisations across the UK.</p>
            </div>
            <div class="quotes">
                <div class="quote">
                    <div class="stars">★★★★★</div>
                    <p>“Friendly, helpful and quick turnaround on any queries. Great service for charities.”</p>
                    <div class="who"><strong>Richard Gahan</strong> · Scout Leader, Greenmount Scouts</div>
                </div>
                <div class="quote">
                    <div class="stars">★★★★★</div>
                    <p>“Charles was extremely helpful from start to finish, nothing was too much trouble. A tremendous amount of knowledge in his field.”</p>
                    <div class="who"><strong>Gillian Cooper</strong> · Owner, Academy Beauty</div>
                </div>
                <div class="quote">
                    <div class="stars">★★★★★</div>
                    <p>“CK Enterprises host several websites for me and I've never had any issues — excellent service and very simple to deal with.”</p>
                    <div class="who"><strong>Carl Bowker-Kemp</strong> · Owner, Green Street</div>
                </div>
                <div class="quote">
                    <div class="stars">★★★★★</div>
                    <p>“Excellent. Excellent service.”</p>
                    <div class="who"><strong>Andy Knowles</strong> · Owner, KBR Print and Design</div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA band -->
    <section class="block alt">
        <div class="container">
            <div class="cta-band">
                <h2>Ready to sell your first ticket?</h2>
                <p>Set up your branded storefront today. Delivered with clear advice, transparent pricing and support you can depend on.</p>
                <div class="cta">
                    <a class="btn btn-teal" href="{{ url('/register') }}">Get started free</a>
                    <a class="btn btn-ghost" href="{{ url('/login') }}">Log in</a>
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
                    <a href="#testimonials">Reviews</a>
                    <a href="{{ url('/login') }}">Log in</a>
                    <a href="{{ url('/register') }}">Get started</a>
                    <a href="https://ckenterprises.co.uk/" target="_blank" rel="noopener">CK Enterprises</a>
                </div>
            </div>
            <div class="copy">
                &copy; {{ date('Y') }} CK Enterprises Group Ltd. All rights reserved.
            </div>
        </div>
    </footer>
</body>
</html>
