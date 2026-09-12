@extends('layouts.app')

@section('title', 'How it works · Events by CK Enterprises UK')
@section('main_class', 'mkt')

@include('marketing._meta', [
    'metaTitle' => 'How it works',
    'description' => 'How organisers create an organisation, connect Stripe, sell tickets and check guests in with Events by CK Enterprises UK.',
    'canonical' => route('how-it-works'),
])

@section('content')
    <section class="mkt-hero">
        <div class="mkt-container">
            <span class="mkt-eyebrow">How it works</span>
            <h1>From setup to the front door.</h1>
            <p class="mkt-hero__lead">
                Create your organisation, connect your own Stripe account, publish your event and check
                guests in on the day. You stay in control of your branding and your money throughout.
            </p>
            <div class="mkt-hero__actions">
                <a class="mkt-btn mkt-btn--primary" href="{{ url('/register') }}">Get started</a>
                <a class="mkt-btn mkt-btn--link-on-dark" href="{{ route('features') }}">View features →</a>
            </div>
        </div>
    </section>

    <section class="mkt-section" aria-labelledby="steps">
        <div class="mkt-container">
            <h2 id="steps" class="sr-only">The steps</h2>
            <ol class="mkt-steps mkt-steps--5" aria-label="How it works in five steps">
                <li class="mkt-step">
                    <div class="mkt-step__num">01</div>
                    <h3>Create your organisation</h3>
                    <p>Create an account and set up your branded storefront.</p>
                </li>
                <li class="mkt-step">
                    <div class="mkt-step__num">02</div>
                    <h3>Connect Stripe</h3>
                    <p>Connect your own Stripe account so ticket payments flow through your payment setup.</p>
                </li>
                <li class="mkt-step">
                    <div class="mkt-step__num">03</div>
                    <h3>Publish your event</h3>
                    <p>Create your event, configure tickets and start selling.</p>
                </li>
                <li class="mkt-step">
                    <div class="mkt-step__num">04</div>
                    <h3>Check guests in</h3>
                    <p>Scan QR tickets from a phone and manage arrivals.</p>
                </li>
                <li class="mkt-step">
                    <div class="mkt-step__num">05</div>
                    <h3>Review sales and attendees</h3>
                    <p>Manage orders, attendees and event information from the organiser dashboard.</p>
                </li>
            </ol>
        </div>
    </section>

    <section class="mkt-cta">
        <div class="mkt-container mkt-cta__inner">
            <div>
                <h2>Set up your first event.</h2>
                <p>Create your organisation and publish an event in a few steps.</p>
            </div>
            <div class="mkt-cta__actions">
                <a class="mkt-btn mkt-btn--on-dark" href="{{ url('/register') }}">Get started</a>
                <a class="mkt-btn mkt-btn--link-on-dark" href="{{ route('features') }}">View features →</a>
            </div>
        </div>
    </section>
@endsection
