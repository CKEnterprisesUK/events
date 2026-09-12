@extends('layouts.app')

@section('title', 'How it works · Events by CK Enterprises UK')
@section('main_class', 'mkt')

@include('marketing._meta', [
    'metaTitle' => 'How it works',
    'description' => 'See how organisers set up their organisation, publish events, sell tickets and manage event day with Events by CK Enterprises UK.',
    'canonical' => route('how-it-works'),
])

@push('styles')
<style>
    .hiw-intro {
        max-width: 760px;
    }

    .hiw-hero-points {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem 1.5rem;
        margin: 2rem 0 0;
        padding: 0;
        list-style: none;
        color: rgba(255, 255, 255, 0.78);
        font-size: 0.95rem;
        font-weight: 600;
    }

    .hiw-hero-points li {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .hiw-hero-points li::before {
        content: "✓";
        color: #2dd4a7;
        font-weight: 800;
    }

    .hiw-section {
        background: #fff;
    }

    .hiw-section--alt {
        background: #f7f8fb;
    }

    .hiw-step {
        display: grid;
        grid-template-columns: minmax(260px, 0.7fr) minmax(0, 1.3fr);
        align-items: center;
        gap: clamp(3rem, 7vw, 7rem);
        padding: clamp(4.5rem, 8vw, 7.5rem) 0;
    }

    .hiw-step--reverse .hiw-step__content {
        order: 2;
    }

    .hiw-step--reverse .hiw-step__visual {
        order: 1;
    }

    .hiw-step__content {
        max-width: 470px;
    }

    .hiw-step__number {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 44px;
        height: 30px;
        margin-bottom: 1.25rem;
        padding: 0 0.7rem;
        border-radius: 999px;
        background: rgba(101, 72, 245, 0.1);
        color: #6548f5;
        font-size: 0.78rem;
        font-weight: 800;
        letter-spacing: 0.1em;
    }

    .hiw-step__content h2 {
        margin: 0;
        color: #111827;
        font-size: clamp(2rem, 3.2vw, 3.25rem);
        line-height: 1.05;
        letter-spacing: -0.04em;
    }

    .hiw-step__content p {
        margin: 1.1rem 0 0;
        color: #667085;
        font-size: 1.08rem;
        line-height: 1.7;
    }

    .hiw-step__tags {
        display: flex;
        flex-wrap: wrap;
        gap: 0.55rem;
        margin-top: 1.6rem;
    }

    .hiw-step__tags span {
        display: inline-flex;
        align-items: center;
        min-height: 34px;
        padding: 0.4rem 0.75rem;
        border: 1px solid #e2e6ee;
        border-radius: 999px;
        background: #fff;
        color: #475467;
        font-size: 0.82rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .hiw-section--alt .hiw-step__tags span {
        background: #fff;
    }

    .hiw-step__visual {
        position: relative;
        min-width: 0;
        margin: 0;
        padding: clamp(0.65rem, 1vw, 0.9rem);
        overflow: hidden;
        border: 1px solid #e3e7ef;
        border-radius: 22px;
        background: #fff;
        box-shadow:
            0 30px 70px rgba(15, 23, 42, 0.09),
            0 4px 15px rgba(15, 23, 42, 0.04);
    }

    .hiw-step__visual::before {
        content: "";
        position: absolute;
        inset: 0;
        pointer-events: none;
        border-radius: inherit;
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
    }

    .hiw-step__visual img {
        display: block;
        width: 100%;
        height: auto;
        border-radius: 14px;
    }

    /*
     * Some source screenshots are naturally taller.
     * Don't force them into a landscape crop.
     */
    .hiw-step__visual--tall {
        justify-self: center;
        width: min(100%, 650px);
    }

    .hiw-step__visual--tall img {
        max-height: 650px;
        object-fit: contain;
        object-position: top center;
    }

    .hiw-step__visual--ticket {
        justify-self: center;
        width: min(100%, 580px);
        background:
            radial-gradient(circle at top left, rgba(101, 72, 245, 0.08), transparent 45%),
            #f8f9fc;
    }

    .hiw-step__visual--ticket img {
        max-height: 650px;
        object-fit: contain;
        object-position: top center;
    }

    .hiw-caption {
        margin: 0.8rem 0 0;
        color: #98a2b3;
        font-size: 0.78rem;
        text-align: center;
    }

    .hiw-journey {
        padding: clamp(3rem, 6vw, 5rem) 0;
        border-bottom: 1px solid #eaecf0;
        background: #fff;
    }

    .hiw-journey__inner {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 0;
        margin-top: 2rem;
        overflow: hidden;
        border: 1px solid #e4e7ec;
        border-radius: 18px;
        background: #fff;
    }

    .hiw-journey__item {
        position: relative;
        padding: 1.4rem;
        border-right: 1px solid #e4e7ec;
    }

    .hiw-journey__item:last-child {
        border-right: 0;
    }

    .hiw-journey__item span {
        display: block;
        margin-bottom: 0.6rem;
        color: #6548f5;
        font-size: 0.75rem;
        font-weight: 800;
        letter-spacing: 0.08em;
    }

    .hiw-journey__item strong {
        display: block;
        color: #182230;
        font-size: 0.95rem;
        line-height: 1.35;
    }

    .hiw-bottom-note {
        padding: 3rem 0 0;
        color: #667085;
        text-align: center;
        font-size: 0.92rem;
    }

    @media (max-width: 1100px) {
        .hiw-journey__inner {
            grid-template-columns: repeat(3, 1fr);
        }

        .hiw-journey__item:nth-child(3) {
            border-right: 0;
        }

        .hiw-journey__item:nth-child(-n+3) {
            border-bottom: 1px solid #e4e7ec;
        }
    }

    @media (max-width: 900px) {
        .hiw-step,
        .hiw-step--reverse {
            grid-template-columns: 1fr;
            gap: 2.5rem;
            padding: 4rem 0;
        }

        .hiw-step--reverse .hiw-step__content,
        .hiw-step--reverse .hiw-step__visual {
            order: initial;
        }

        .hiw-step__content {
            max-width: 650px;
        }

        .hiw-step__visual,
        .hiw-step__visual--tall,
        .hiw-step__visual--ticket {
            width: 100%;
            justify-self: stretch;
        }

        .hiw-step__visual--tall img,
        .hiw-step__visual--ticket img {
            max-height: none;
        }
    }

    @media (max-width: 680px) {
        .hiw-hero-points {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.65rem;
        }

        .hiw-step {
            padding: 3.25rem 0;
        }

        .hiw-step__content h2 {
            font-size: clamp(1.9rem, 9vw, 2.6rem);
        }

        .hiw-step__content p {
            font-size: 1rem;
        }

        .hiw-step__visual {
            padding: 0.4rem;
            border-radius: 15px;
        }

        .hiw-step__visual img {
            border-radius: 10px;
        }

        .hiw-journey__inner {
            grid-template-columns: 1fr 1fr;
        }

        .hiw-journey__item {
            border-bottom: 1px solid #e4e7ec;
        }

        .hiw-journey__item:nth-child(odd) {
            border-right: 1px solid #e4e7ec;
        }

        .hiw-journey__item:nth-child(even) {
            border-right: 0;
        }

        .hiw-journey__item:nth-last-child(-n+2) {
            border-bottom: 0;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .hiw-step__visual {
            scroll-behavior: auto;
        }
    }
</style>
@endpush

@section('content')

    {{-- Hero --}}
    <section class="mkt-hero">
        <div class="mkt-container">
            <div class="hiw-intro">
                <span class="mkt-eyebrow">How it works</span>

                <h1>From setup to the front door.</h1>

                <p class="mkt-hero__lead">
                    Create your organisation, sell tickets under your own brand and manage
                    event day from the same platform.
                </p>

                <div class="mkt-hero__actions">
                    <a class="mkt-btn mkt-btn--primary" href="{{ url('/register') }}">
                        Get started
                    </a>

                    <a class="mkt-btn mkt-btn--link-on-dark" href="{{ route('features') }}">
                        View features →
                    </a>
                </div>

                <ul class="hiw-hero-points" aria-label="Key benefits">
                    <li>No setup fee</li>
                    <li>No monthly subscription</li>
                    <li>Direct Stripe payouts</li>
                    <li>No buyer accounts</li>
                </ul>
            </div>
        </div>
    </section>


    {{-- Quick journey --}}
    <section class="hiw-journey" aria-labelledby="journey-heading">
        <div class="mkt-container">
            <span class="mkt-eyebrow">The journey</span>

            <h2 id="journey-heading">
                Six simple steps from signup to event day.
            </h2>

            <div class="hiw-journey__inner">
                <div class="hiw-journey__item">
                    <span>01</span>
                    <strong>Create your organisation</strong>
                </div>

                <div class="hiw-journey__item">
                    <span>02</span>
                    <strong>Build your storefront</strong>
                </div>

                <div class="hiw-journey__item">
                    <span>03</span>
                    <strong>Publish your event</strong>
                </div>

                <div class="hiw-journey__item">
                    <span>04</span>
                    <strong>Customers book</strong>
                </div>

                <div class="hiw-journey__item">
                    <span>05</span>
                    <strong>Tickets are issued</strong>
                </div>

                <div class="hiw-journey__item">
                    <span>06</span>
                    <strong>Manage your event</strong>
                </div>
            </div>
        </div>
    </section>


    {{-- Step 1 --}}
    <section class="hiw-section" aria-labelledby="step-one">
        <div class="mkt-container">
            <div class="hiw-step">

                <div class="hiw-step__content">
                    <span class="hiw-step__number">01</span>

                    <h2 id="step-one">Create your organisation.</h2>

                    <p>
                        Add your organisation details, choose your storefront address
                        and create your account.
                    </p>

                    <div class="hiw-step__tags">
                        <span>Quick setup</span>
                        <span>Your storefront URL</span>
                    </div>
                </div>

                <figure class="hiw-step__visual hiw-step__visual--tall">
                    <img
                        src="{{ asset('images/marketing/signup.png') }}"
                        alt="Events by CK Enterprises UK organisation signup screen"
                        width="1600"
                        height="1000"
                        fetchpriority="high"
                    >
                </figure>

            </div>
        </div>
    </section>


    {{-- Step 2 --}}
    <section class="hiw-section hiw-section--alt" aria-labelledby="step-two">
        <div class="mkt-container">
            <div class="hiw-step hiw-step--reverse">

                <div class="hiw-step__content">
                    <span class="hiw-step__number">02</span>

                    <h2 id="step-two">Make it yours.</h2>

                    <p>
                        Your organisation gets its own branded storefront for customers
                        to discover your events.
                    </p>

                    <div class="hiw-step__tags">
                        <span>Your branding</span>
                        <span>Your events</span>
                        <span>Not a marketplace</span>
                    </div>
                </div>

                <figure class="hiw-step__visual">
                    <img
                        src="{{ asset('images/marketing/storefront.png') }}"
                        alt="Example branded organisation storefront"
                        width="1600"
                        height="1000"
                        loading="lazy"
                    >
                </figure>

            </div>
        </div>
    </section>


    {{-- Step 3 --}}
    <section class="hiw-section" aria-labelledby="step-three">
        <div class="mkt-container">
            <div class="hiw-step">

                <div class="hiw-step__content">
                    <span class="hiw-step__number">03</span>

                    <h2 id="step-three">Publish and start selling.</h2>

                    <p>
                        Customers see your event, the important details and the tickets
                        available to buy.
                    </p>

                    <div class="hiw-step__tags">
                        <span>Event details</span>
                        <span>Ticket types</span>
                        <span>Clear pricing</span>
                    </div>
                </div>

                <figure class="hiw-step__visual">
                    <img
                        src="{{ asset('images/marketing/storefront2.png') }}"
                        alt="Example public event and ticket sales page"
                        width="1600"
                        height="1000"
                        loading="lazy"
                    >
                </figure>

            </div>
        </div>
    </section>


    {{-- Step 4 --}}
    <section class="hiw-section hiw-section--alt" aria-labelledby="step-four">
        <div class="mkt-container">
            <div class="hiw-step hiw-step--reverse">

                <div class="hiw-step__content">
                    <span class="hiw-step__number">04</span>

                    <h2 id="step-four">Customers book without an account.</h2>

                    <p>
                        Choose tickets, enter the details needed for the order and
                        continue to secure payment.
                    </p>

                    <div class="hiw-step__tags">
                        <span>No buyer account</span>
                        <span>Secure checkout</span>
                        <span>Stripe payments</span>
                    </div>
                </div>

                <figure class="hiw-step__visual hiw-step__visual--tall">
                    <img
                        src="{{ asset('images/marketing/checkout.png') }}"
                        alt="Example customer ticket checkout screen"
                        width="1600"
                        height="1000"
                        loading="lazy"
                    >
                </figure>

            </div>
        </div>
    </section>


    {{-- Step 5 --}}
    <section class="hiw-section" aria-labelledby="step-five">
        <div class="mkt-container">
            <div class="hiw-step">

                <div class="hiw-step__content">
                    <span class="hiw-step__number">05</span>

                    <h2 id="step-five">Tickets arrive ready to scan.</h2>

                    <p>
                        Customers receive a clear ticket containing their event details
                        and a unique QR code.
                    </p>

                    <div class="hiw-step__tags">
                        <span>QR tickets</span>
                        <span>Event details</span>
                        <span>Branded for your event</span>
                    </div>
                </div>

                <figure class="hiw-step__visual hiw-step__visual--ticket">
                    <img
                        src="{{ asset('images/marketing/ticket.png') }}"
                        alt="Example event ticket with event details and QR code"
                        width="1200"
                        height="1600"
                        loading="lazy"
                    >
                </figure>

            </div>
        </div>
    </section>


    {{-- Step 6 --}}
    <section class="hiw-section hiw-section--alt" aria-labelledby="step-six">
        <div class="mkt-container">
            <div class="hiw-step hiw-step--reverse">

                <div class="hiw-step__content">
                    <span class="hiw-step__number">06</span>

                    <h2 id="step-six">Manage everything in one place.</h2>

                    <p>
                        Keep your events, orders, customers, reports and event-day
                        tools together in the organiser dashboard.
                    </p>

                    <div class="hiw-step__tags">
                        <span>Orders</span>
                        <span>Customers</span>
                        <span>Reports & payouts</span>
                        <span>Ticket scanning</span>
                    </div>
                </div>

                <figure class="hiw-step__visual">
                    <img
                        src="{{ asset('images/marketing/dashboard.png') }}"
                        alt="Events by CK Enterprises UK organiser dashboard"
                        width="1600"
                        height="1000"
                        loading="lazy"
                    >
                </figure>

            </div>

            <p class="hiw-bottom-note">
                On event day, use the organiser tools on your phone to scan ticket QR codes and manage arrivals.
            </p>
        </div>
    </section>


    {{-- CTA --}}
    <section class="mkt-cta">
        <div class="mkt-container mkt-cta__inner">

            <div>
                <h2>Ready to run your first event?</h2>

                <p>
                    No setup fee. No monthly subscription.
                </p>
            </div>

            <div class="mkt-cta__actions">
                <a class="mkt-btn mkt-btn--on-dark" href="{{ url('/register') }}">
                    Get started
                </a>

                <a class="mkt-btn mkt-btn--link-on-dark" href="{{ route('pricing') }}">
                    View pricing →
                </a>
            </div>

        </div>
    </section>

@endsection