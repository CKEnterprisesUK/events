@extends('layouts.app')

@section('title', 'For charities · Events by CK Enterprises UK')
@section('main_class', 'mkt')

@include('marketing._meta', [
    'metaTitle' => 'Event ticketing for charities and community organisations',
    'description' => 'Event ticketing for charities and community organisations, built by a team already supporting charities and small organisations.',
    'canonical' => route('for-charities'),
])

@section('content')
    <section class="mkt-hero">
        <div class="mkt-container">
            <span class="mkt-eyebrow">For charities</span>
            <h1>Built by a team already supporting charities and community organisations.</h1>
            <p class="mkt-hero__lead">
                Events by CK Enterprises UK is built by the same team already providing digital services to
                charities and small, mission-driven organisations. It brings that same practical, support-led
                approach to selling tickets.
            </p>
            <div class="mkt-hero__actions">
                <a class="mkt-btn mkt-btn--primary" href="{{ url('/register') }}">Get started</a>
                <a class="mkt-btn mkt-btn--link-on-dark" href="https://ckenterprises.co.uk/#contact" target="_blank" rel="noopener">Talk to us about tailored pricing →</a>
            </div>
        </div>
    </section>

    <section class="mkt-section" aria-labelledby="charity-benefits">
        <div class="mkt-container">
            <div class="mkt-section-head">
                <span class="mkt-eyebrow">Practical benefits</span>
                <h2 id="charity-benefits">Straightforward ticketing that leaves more with your cause.</h2>
            </div>

            <div class="mkt-grid">
                <div class="mkt-cell"><h3>No monthly subscription</h3><p>No recurring platform cost to budget for between events.</p></div>
                <div class="mkt-cell"><h3>No setup cost</h3><p>Start selling without an upfront commitment.</p></div>
                <div class="mkt-cell"><h3>Direct Stripe payouts</h3><p>Ticket income settles into your own connected Stripe account.</p></div>
                <div class="mkt-cell"><h3>Your own branding</h3><p>Sell on a storefront that represents your organisation.</p></div>
                <div class="mkt-cell"><h3>No buyer accounts</h3><p>A simple checkout your supporters can complete in a few taps.</p></div>
                <div class="mkt-cell"><h3>No advertising trackers</h3><p>Supporter data stays focused on your event.</p></div>
            </div>

            <div class="mkt-callout">
                <strong>Tailored pricing for eligible organisations</strong>
                Charities and community organisations can talk to us about pricing that suits their situation.
            </div>
        </div>
    </section>

    <section class="mkt-cta">
        <div class="mkt-container mkt-cta__inner">
            <div>
                <h2>Let's talk about your events.</h2>
                <p>Get started now, or contact us to discuss tailored pricing for your organisation.</p>
            </div>
            <div class="mkt-cta__actions">
                <a class="mkt-btn mkt-btn--on-dark" href="{{ url('/register') }}">Get started</a>
                <a class="mkt-btn mkt-btn--link-on-dark" href="https://ckenterprises.co.uk/#contact" target="_blank" rel="noopener">Talk to us about tailored pricing →</a>
            </div>
        </div>
    </section>
@endsection
