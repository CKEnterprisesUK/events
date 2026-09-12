@extends('layouts.app')

@section('title', 'Event ticketing for charities and community organisations · Events by CK Enterprises UK')
@section('main_class', 'mkt')

@include('marketing._meta', [
    'metaTitle' => 'Event ticketing for charities and community organisations',
    'description' => 'Sell tickets under your own brand and get paid directly through your connected Stripe account. Event ticketing for charities, community organisations and small organisations.',
    'canonical' => url('/'),
])

@php
    // The homepage pricing preview reads the live Global_Fee_Percent so it never
    // drifts from the Pricing page / calculator. Whole numbers render without
    // trailing decimals (5, not 5.00).
    $feeDisplay = number_format($feePercent, $feePercent == (int) $feePercent ? 0 : 2);
@endphp

@section('content')
    {{-- 1. Hero ------------------------------------------------------------ --}}
    <section class="mkt-hero">
        <div class="mkt-container mkt-hero__grid">
            <div>
                <span class="mkt-eyebrow">Event ticketing, without the marketplace</span>

                <h1>Sell tickets under your own brand.<br>Get paid directly.</h1>

                <p class="mkt-hero__lead">
                    Create branded event pages, take payments through your connected Stripe account
                    and check guests in from your phone.
                </p>

                <div class="mkt-hero__actions">
                    @auth
                        <a class="mkt-btn mkt-btn--primary" href="{{ route('dashboard.home') }}">Go to dashboard</a>
                    @else
                        <a class="mkt-btn mkt-btn--primary" href="{{ url('/register') }}">Create your organisation</a>
                    @endauth
                    <a class="mkt-btn mkt-btn--link-on-dark" href="{{ route('how-it-works') }}">See how it works →</a>
                </div>

                <ul class="mkt-reassure" aria-label="Key benefits">
                    <li>No monthly subscription</li>
                    <li>Direct Stripe payouts</li>
                    <li>No buyer accounts</li>
                </ul>
            </div>

            <div class="mkt-hero__visual">
                {{-- Lightweight, real-UI-flavoured storefront preview built from
                     existing styling primitives — not a dense illustration. It
                     is a non-interactive illustration, so the whole card links
                     through to "How it works" rather than exposing controls
                     (like the ticket "Continue") that don't do anything here. --}}
                <a class="mkt-preview" href="{{ route('how-it-works') }}"
                   aria-label="See how selling tickets works">
                    <div class="mkt-preview__bar" aria-hidden="true">
                        <span class="mkt-preview__dot"></span>
                        <span class="mkt-preview__dot"></span>
                        <span class="mkt-preview__dot"></span>
                        <span class="mkt-preview__url">yourorganisation.events</span>
                    </div>
                    <div class="mkt-preview__body" aria-hidden="true">
                        <div class="mkt-preview__eyebrow">Your organisation</div>
                        <div class="mkt-preview__title">Summer Fundraiser</div>
                        <div class="mkt-preview__meta">Sat 12 July · The Community Hall</div>

                        <div class="mkt-preview__ticket">
                            <span>
                                <strong>General admission</strong>
                                <span>On sale</span>
                            </span>
                            <span class="mkt-preview__price">£10.00</span>
                        </div>
                        <div class="mkt-preview__ticket">
                            <span>
                                <strong>Supporter</strong>
                                <span>On sale</span>
                            </span>
                            <span class="mkt-preview__price">£25.00</span>
                        </div>

                        <div class="mkt-preview__cta">See how it works →</div>
                    </div>
                </a>
            </div>
        </div>
    </section>

    {{-- 2. Core product benefits / feature preview ------------------------- --}}
    <section class="mkt-section" aria-labelledby="home-features">
        <div class="mkt-container">
            <div class="mkt-section-head">
                <span class="mkt-eyebrow">What you get</span>
                <h2 id="home-features">Everything you need to sell and run your event.</h2>
                <p>Branded ticketing, direct payouts and phone-based check-in, built by a UK team that already supports charities and small organisations.</p>
            </div>

            <div class="mkt-grid mkt-grid--2">
                <div class="mkt-cell">
                    <span class="mkt-cell__ico" aria-hidden="true">🎟️</span>
                    <h3>Your own storefront</h3>
                    <p>Sell under your branding on your own event pages, not a competing marketplace.</p>
                </div>
                <div class="mkt-cell">
                    <span class="mkt-cell__ico" aria-hidden="true">👤</span>
                    <h3>No buyer accounts</h3>
                    <p>Customers buy in a few taps. No account sign-up to slow checkout down.</p>
                </div>
                <div class="mkt-cell">
                    <span class="mkt-cell__ico" aria-hidden="true">🔒</span>
                    <h3>No advertising trackers</h3>
                    <p>Your customer data stays focused on your event, not fed into ad networks.</p>
                </div>
                <div class="mkt-cell">
                    <span class="mkt-cell__ico" aria-hidden="true">📱</span>
                    <h3>Phone-based check-in</h3>
                    <p>Scan QR tickets from a modern phone browser. No specialist hardware.</p>
                </div>
            </div>

            <div class="mkt-section-cta">
                <a class="mkt-btn mkt-btn--ghost" href="{{ route('features') }}">Explore all features →</a>
            </div>
        </div>
    </section>

    {{-- 3 + 4. Product-led split: how it works preview --------------------- --}}
    <section class="mkt-section mkt-section--soft" aria-labelledby="home-how">
        <div class="mkt-container mkt-split">
            <div>
                <span class="mkt-eyebrow">How it works</span>
                <h2 id="home-how">From setup to the front door.</h2>
                <p class="mkt-note">
                    Create your organisation, connect your own Stripe account, publish your event and
                    check guests in on the day. You stay in control of your branding and your money throughout.
                </p>
                <div class="mkt-section-cta">
                    <a class="mkt-btn mkt-btn--ghost" href="{{ route('how-it-works') }}">See how it works →</a>
                </div>
            </div>

            <ol class="mkt-checks" aria-label="How it works in four steps">
                <li><span><strong>Create</strong><span>Set up your organisation and branded storefront.</span></span></li>
                <li><span><strong>Connect Stripe</strong><span>Payments flow through your own Stripe account.</span></span></li>
                <li><span><strong>Sell tickets</strong><span>Publish your event and share your storefront link.</span></span></li>
                <li><span><strong>Check guests in</strong><span>Scan QR tickets from a phone on the day.</span></span></li>
            </ol>
        </div>
    </section>

    {{-- 5. Pricing preview ------------------------------------------------- --}}
    <section class="mkt-section" aria-labelledby="home-pricing">
        <div class="mkt-container">
            <div class="mkt-section-head">
                <span class="mkt-eyebrow">Simple pricing</span>
                <h2 id="home-pricing">No setup fee. No monthly subscription.</h2>
            </div>

            <div class="mkt-price-preview">
                <div>
                    <div class="mkt-price-preview__big"><span>{{ $feeDisplay }}% per paid ticket</span></div>
                    <p class="mkt-price-sub">+ Stripe payment processing. You only pay when you sell a paid ticket.</p>
                </div>
                <a class="mkt-btn mkt-btn--secondary" href="{{ route('pricing') }}">Calculate your costs →</a>
            </div>
        </div>
    </section>

    {{-- 6. For charities preview ------------------------------------------- --}}
    <section class="mkt-section mkt-section--alt" aria-labelledby="home-charities">
        <div class="mkt-container mkt-split">
            <div>
                <span class="mkt-eyebrow">For charities</span>
                <h2 id="home-charities">Built with charities and community organisations in mind.</h2>
                <p class="mkt-note">
                    Events by CK Enterprises UK is built by the same team already providing digital
                    services to charities and small organisations.
                </p>
                <div class="mkt-section-cta">
                    <a class="mkt-btn mkt-btn--ghost" href="{{ route('for-charities') }}">For charities →</a>
                </div>
            </div>

            <ul class="mkt-checks" aria-label="Practical benefits for charities">
                <li><span><strong>No monthly subscription or setup cost</strong><span>Start selling without an upfront commitment.</span></span></li>
                <li><span><strong>Direct Stripe payouts</strong><span>Ticket income goes to your own connected account.</span></span></li>
                <li><span><strong>Tailored pricing conversations</strong><span>Eligible organisations can discuss rates with us.</span></span></li>
            </ul>
        </div>
    </section>

    {{-- 7. Trust / privacy / payments reassurance -------------------------- --}}
    <section class="mkt-section" aria-labelledby="home-trust">
        <div class="mkt-container">
            <div class="mkt-section-head">
                <span class="mkt-eyebrow">Trust</span>
                <h2 id="home-trust">Payments, privacy and policies you can point to.</h2>
            </div>

            <div class="mkt-chips">
                <div class="mkt-chip"><strong>Payments through Stripe</strong><span>Card processing is handled by Stripe.</span></div>
                <div class="mkt-chip"><strong>No advertising trackers</strong><span>Data stays focused on your event.</span></div>
                <div class="mkt-chip"><strong>Clear data handling</strong><span>Published privacy and data policies.</span></div>
                <div class="mkt-chip"><strong>Accessible support</strong><span>Policies and support in one place.</span></div>
            </div>

            <div class="mkt-section-cta">
                <a class="mkt-btn mkt-btn--ghost" href="{{ route('trust.index') }}">Visit the Trust Centre →</a>
            </div>
        </div>
    </section>

    {{-- 8. Final CTA ------------------------------------------------------- --}}
    <section class="mkt-cta">
        <div class="mkt-container mkt-cta__inner">
            <div>
                <h2>Planning an event?</h2>
                <p>Start with the platform, or speak to CK Enterprises UK about your organisation, expected ticket volume or charity pricing.</p>
            </div>
            <div class="mkt-cta__actions">
                @auth
                    <a class="mkt-btn mkt-btn--on-dark" href="{{ route('dashboard.home') }}">Go to dashboard</a>
                @else
                    <a class="mkt-btn mkt-btn--on-dark" href="{{ url('/register') }}">Create your organisation</a>
                @endauth
                <a class="mkt-btn mkt-btn--link-on-dark" href="https://ckenterprises.co.uk/#contact" target="_blank" rel="noopener">Talk to us</a>
            </div>
        </div>
    </section>
@endsection
