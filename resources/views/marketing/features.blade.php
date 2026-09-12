@extends('layouts.app')

@section('title', 'Features · Events by CK Enterprises UK')
@section('main_class', 'mkt')

@include('marketing._meta', [
    'metaTitle' => 'Event ticketing features',
    'description' => 'Event ticketing features including branded storefronts, Stripe payouts and mobile QR check-in for charities and small organisations.',
    'canonical' => route('features'),
])

@section('content')
    <section class="mkt-hero">
        <div class="mkt-container">
            <span class="mkt-eyebrow">Features</span>
            <h1>Sell, get paid, and run your event.</h1>
            <p class="mkt-hero__lead">
                A focused set of tools for selling tickets under your own brand, taking payments through
                your connected Stripe account and checking guests in on the day.
            </p>
            <div class="mkt-hero__actions">
                <a class="mkt-btn mkt-btn--primary" href="{{ url('/register') }}">Get started</a>
                <a class="mkt-btn mkt-btn--link-on-dark" href="{{ route('pricing') }}">See pricing →</a>
            </div>
        </div>
    </section>

    {{-- Sell --}}
    <section class="mkt-section" aria-labelledby="feat-sell">
        <div class="mkt-container">
            <div class="mkt-section-head">
                <span class="mkt-eyebrow">Sell</span>
                <h2 id="feat-sell">Branded ticketing on your own storefront.</h2>
            </div>
            <div class="mkt-grid">
                <div class="mkt-cell"><h3>Branded event storefront</h3><p>Your storefront carries your branding, not a competing-event marketplace.</p></div>
                <div class="mkt-cell"><h3>Event pages</h3><p>Publish clear event pages with the details, location and tickets customers need.</p></div>
                <div class="mkt-cell"><h3>Ticket types</h3><p>Create multiple paid or free ticket types with their own prices and sale windows.</p></div>
                <div class="mkt-cell"><h3>Capacity management</h3><p>Set per-type capacity, a shared pool or unlimited, and stay inside your limits.</p></div>
                <div class="mkt-cell"><h3>No buyer accounts</h3><p>Customers check out in a few taps without creating an account.</p></div>
                <div class="mkt-cell"><h3>Ticket delivery</h3><p>Confirmed orders receive a branded confirmation email with their tickets attached.</p></div>
            </div>
        </div>
    </section>

    {{-- Get paid --}}
    <section class="mkt-section mkt-section--soft" aria-labelledby="feat-paid">
        <div class="mkt-container">
            <div class="mkt-section-head">
                <span class="mkt-eyebrow">Get paid</span>
                <h2 id="feat-paid">Money flows through your own Stripe account.</h2>
            </div>
            <div class="mkt-grid mkt-grid--2">
                <div class="mkt-cell"><h3>Connected Stripe account</h3><p>Link your own Stripe account so payments settle through your organisation.</p></div>
                <div class="mkt-cell"><h3>Direct payouts</h3><p>Ticket revenue goes to your connected account, not a pooled platform wallet.</p></div>
                <div class="mkt-cell"><h3>Transparent platform pricing</h3><p>A clear percentage per paid ticket, with no setup fee or monthly subscription.</p></div>
                <div class="mkt-cell"><h3>Refund handling</h3><p>Cancel or refund orders from the dashboard, with refunds issued on your Stripe account.</p></div>
            </div>
        </div>
    </section>

    {{-- Run your event --}}
    <section class="mkt-section" aria-labelledby="feat-run">
        <div class="mkt-container">
            <div class="mkt-section-head">
                <span class="mkt-eyebrow">Run your event</span>
                <h2 id="feat-run">Check people in without specialist hardware.</h2>
            </div>
            <div class="mkt-grid">
                <div class="mkt-cell"><h3>QR tickets</h3><p>Every confirmed ticket carries a QR code tied to its order.</p></div>
                <div class="mkt-cell"><h3>Phone-based check-in</h3><p>Validate tickets from a modern phone browser at the door.</p></div>
                <div class="mkt-cell"><h3>Duplicate scan detection</h3><p>Already-scanned tickets are flagged so the same ticket can't slip through twice.</p></div>
                <div class="mkt-cell"><h3>Attendee and order lookup</h3><p>Find an order or attendee quickly by reference, name or email.</p></div>
                <div class="mkt-cell"><h3>Resend tickets</h3><p>Re-send a customer's tickets or download the ticket PDF when needed.</p></div>
                <div class="mkt-cell"><h3>Reset check-ins</h3><p>Clear an event's check-ins when you need a clean start, with the change recorded.</p></div>
            </div>
        </div>
    </section>

    {{-- Manage --}}
    <section class="mkt-section mkt-section--soft" aria-labelledby="feat-manage">
        <div class="mkt-container">
            <div class="mkt-section-head">
                <span class="mkt-eyebrow">Manage</span>
                <h2 id="feat-manage">One dashboard for events, orders and your team.</h2>
            </div>
            <div class="mkt-grid">
                <div class="mkt-cell"><h3>Organiser dashboard</h3><p>See sales and manage your events from a single place.</p></div>
                <div class="mkt-cell"><h3>Team access</h3><p>Invite colleagues and give them the right level of access to your events.</p></div>
                <div class="mkt-cell"><h3>Attendee exports</h3><p>Export attendee and answer data for your records and planning.</p></div>
                <div class="mkt-cell"><h3>Event management</h3><p>Create, edit, publish, cancel and report on events as they progress.</p></div>
                <div class="mkt-cell"><h3>Order management</h3><p>Review orders, issue refunds and keep track of what's been sold.</p></div>
                <div class="mkt-cell"><h3>Security controls</h3><p>Two-factor authentication and an activity trail on key actions.</p></div>
            </div>
        </div>
    </section>

    <section class="mkt-cta">
        <div class="mkt-container mkt-cta__inner">
            <div>
                <h2>Ready to sell your tickets?</h2>
                <p>Create your organisation and publish your first event, or take a look at the pricing.</p>
            </div>
            <div class="mkt-cta__actions">
                <a class="mkt-btn mkt-btn--on-dark" href="{{ url('/register') }}">Get started</a>
                <a class="mkt-btn mkt-btn--link-on-dark" href="{{ route('pricing') }}">See pricing →</a>
            </div>
        </div>
    </section>
@endsection
