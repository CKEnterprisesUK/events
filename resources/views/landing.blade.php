@extends('layouts.app')

@section('title', config('app.name', 'Event Ticketing Platform'))

@section('content')
    <section class="hero">
        <h1>Sell tickets from your own branded storefront</h1>
        <p class="lead">
            The platform for charities and event organisers to sell tickets online,
            collect payments straight into their own Stripe account, and check attendees
            in with a phone-browser QR scanner.
        </p>
        <div class="cta">
            <a class="btn" href="{{ url('/register') }}">Get started free</a>
            <a class="btn btn-outline" href="{{ url('/login') }}">Log in to your dashboard</a>
        </div>
    </section>

    <section class="features">
        <div class="feature-card">
            <h3>Your own storefront</h3>
            <p>Every organisation gets a branded storefront with your logo, colours and terms — on your own URL.</p>
        </div>
        <div class="feature-card">
            <h3>Direct payouts</h3>
            <p>Connect your Stripe account and take card payments that settle directly to you, no middleman holding your money.</p>
        </div>
        <div class="feature-card">
            <h3>Fast door check-in</h3>
            <p>Scan tickets from any phone browser. QR codes are verified instantly and can only be used once.</p>
        </div>
        <div class="feature-card">
            <h3>Roles for your team</h3>
            <p>Invite admins, accountants and scanners with the right access. You stay in control as the account Owner.</p>
        </div>
    </section>
@endsection
