@extends('layouts.app')

@section('title', 'How it works · Events by CK Enterprises UK')
@section('main_class', 'mkt')

@include('marketing._meta', [
    'metaTitle' => 'How it works',
    'description' => 'See how organisers create an organisation, connect Stripe, publish events, sell tickets and check guests in with Events by CK Enterprises UK.',
    'canonical' => route('how-it-works'),
])

@section('content')
    <section class="mkt-hero">
        <div class="mkt-container">
            <span class="mkt-eyebrow">How it works</span>

            <h1>From setup to the front door.</h1>

            <p class="mkt-hero__lead">
                Create your organisation, connect your own Stripe account, publish your event and start
                selling. When event day arrives, scan tickets from your phone and manage arrivals from
                the same platform.
            </p>

            <div class="mkt-hero__actions">
                <a class="mkt-btn mkt-btn--primary" href="{{ url('/register') }}">
                    Get started
                </a>

                <a class="mkt-btn mkt-btn--link-on-dark" href="{{ route('features') }}">
                    Explore features →
                </a>
            </div>

            <ul class="mkt-hero__checks" aria-label="Key benefits">
                <li>No setup fee</li>
                <li>Direct Stripe payouts</li>
                <li>No buyer accounts</li>
                <li>No specialist check-in hardware</li>
            </ul>
        </div>
    </section>

    <section class="mkt-section mkt-section--compact" aria-labelledby="journey-overview">
        <div class="mkt-container">
            <div class="mkt-section__header">
                <span class="mkt-eyebrow">The journey</span>
                <h2 id="journey-overview">Everything you need to go from idea to event day.</h2>
                <p>
                    Set up your organisation once, then create and run events from the same organiser
                    dashboard.
                </p>
            </div>

            <ol class="mkt-steps mkt-steps--6" aria-label="How it works in six steps">
                <li class="mkt-step">
                    <div class="mkt-step__num" aria-hidden="true">01</div>
                    <h3>Create your organisation</h3>
                    <p>Set up your account and branded public storefront.</p>
                </li>

                <li class="mkt-step">
                    <div class="mkt-step__num" aria-hidden="true">02</div>
                    <h3>Connect Stripe</h3>
                    <p>Connect your own Stripe account to accept paid bookings.</p>
                </li>

                <li class="mkt-step">
                    <div class="mkt-step__num" aria-hidden="true">03</div>
                    <h3>Create your event</h3>
                    <p>Add the event details, tickets, prices and capacity.</p>
                </li>

                <li class="mkt-step">
                    <div class="mkt-step__num" aria-hidden="true">04</div>
                    <h3>Customers book</h3>
                    <p>Customers choose tickets and check out without creating an account.</p>
                </li>

                <li class="mkt-step">
                    <div class="mkt-step__num" aria-hidden="true">05</div>
                    <h3>Check guests in</h3>
                    <p>Scan ticket QR codes from a phone and manage arrivals.</p>
                </li>

                <li class="mkt-step">
                    <div class="mkt-step__num" aria-hidden="true">06</div>
                    <h3>Manage everything</h3>
                    <p>Keep track of events, orders, customers and attendees from your dashboard.</p>
                </li>
            </ol>
        </div>
    </section>

    <section class="mkt-section mkt-section--alt" aria-labelledby="step-create">
        <div class="mkt-container">
            <div class="mkt-feature-split">
                <div class="mkt-feature-split__content">
                    <span class="mkt-step-label">01 · Create your organisation</span>

                    <h2 id="step-create">Start with your organisation, not a marketplace profile.</h2>

                    <p>
                        Create your organiser account and choose the public address for your storefront.
                        Add your organisation details and branding so customers know exactly who they are
                        buying from.
                    </p>

                    <ul class="mkt-check-list">
                        <li>Your own public storefront</li>
                        <li>Your organisation name and branding</li>
                        <li>A single place for all of your events</li>
                        <li>Team access when you need it</li>
                    </ul>
                </div>

                <div class="mkt-feature-split__visual" aria-hidden="true">
                    <div class="mkt-product-card">
                        <div class="mkt-product-card__header">
                            <span>Organisation setup</span>
                            <span>1 of 3</span>
                        </div>

                        <div class="mkt-product-card__body">
                            <div class="mkt-ui-field">
                                <span class="mkt-ui-label">Organisation name</span>
                                <span class="mkt-ui-input">Greenmount Scout Group</span>
                            </div>

                            <div class="mkt-ui-field">
                                <span class="mkt-ui-label">Storefront address</span>
                                <span class="mkt-ui-input">events.ckent.uk/greenmountscouts</span>
                            </div>

                            <div class="mkt-ui-row">
                                <span class="mkt-ui-chip">Organisation</span>
                                <span class="mkt-ui-chip">Address</span>
                                <span class="mkt-ui-chip">Your account</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="mkt-section" aria-labelledby="step-stripe">
        <div class="mkt-container">
            <div class="mkt-feature-split mkt-feature-split--reverse">
                <div class="mkt-feature-split__content">
                    <span class="mkt-step-label">02 · Connect Stripe</span>

                    <h2 id="step-stripe">Your ticket revenue goes through your connected Stripe account.</h2>

                    <p>
                        Connect Stripe during setup so you can accept paid bookings. Payments are processed
                        through your connected account rather than being held in a separate Events wallet.
                    </p>

                    <ul class="mkt-check-list">
                        <li>Secure Stripe-hosted onboarding</li>
                        <li>Payments through your connected Stripe account</li>
                        <li>Clear platform pricing</li>
                        <li>Payment and payout information in your organiser area</li>
                    </ul>
                </div>

                <div class="mkt-feature-split__visual">
                    <div class="mkt-flow" aria-label="Payment flow">
                        <div class="mkt-flow__item">
                            <span class="mkt-flow__eyebrow">Customer</span>
                            <strong>Chooses tickets</strong>
                        </div>

                        <div class="mkt-flow__arrow" aria-hidden="true">→</div>

                        <div class="mkt-flow__item">
                            <span class="mkt-flow__eyebrow">Payment</span>
                            <strong>Stripe checkout</strong>
                        </div>

                        <div class="mkt-flow__arrow" aria-hidden="true">→</div>

                        <div class="mkt-flow__item">
                            <span class="mkt-flow__eyebrow">Funds</span>
                            <strong>Your connected Stripe account</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="mkt-section mkt-section--alt" aria-labelledby="step-event">
        <div class="mkt-container">
            <div class="mkt-feature-split">
                <div class="mkt-feature-split__content">
                    <span class="mkt-step-label">03 · Create and publish</span>

                    <h2 id="step-event">Build the event, configure your tickets and publish when you are ready.</h2>

                    <p>
                        Add the information customers need, create your ticket types and control how many
                        places are available. Your event appears on your organisation's branded storefront
                        when you publish it.
                    </p>

                    <ul class="mkt-check-list">
                        <li>Event date, time and venue</li>
                        <li>Paid and free ticket types</li>
                        <li>Ticket and event capacity controls</li>
                        <li>Customer-facing event information</li>
                    </ul>
                </div>

                <div class="mkt-feature-split__visual" aria-hidden="true">
                    <div class="mkt-product-card">
                        <div class="mkt-product-card__header">
                            <span>Event setup</span>
                            <span>Draft</span>
                        </div>

                        <div class="mkt-product-card__body">
                            <div class="mkt-ui-event">
                                <span class="mkt-ui-event__eyebrow">SATURDAY 7 NOVEMBER</span>
                                <strong>Annual Bonfire 2026</strong>
                                <span>Cannon Lewis Hall · 18:30</span>
                            </div>

                            <div class="mkt-ui-ticket">
                                <div>
                                    <strong>Adults (16+)</strong>
                                    <span>£6.00</span>
                                </div>
                                <span class="mkt-ui-status">On sale</span>
                            </div>

                            <div class="mkt-ui-ticket">
                                <div>
                                    <strong>Child (under 16s)</strong>
                                    <span>£2.00</span>
                                </div>
                                <span class="mkt-ui-status">On sale</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="mkt-section" aria-labelledby="step-book">
        <div class="mkt-container">
            <div class="mkt-feature-split mkt-feature-split--reverse">
                <div class="mkt-feature-split__content">
                    <span class="mkt-step-label">04 · Customers book</span>

                    <h2 id="step-book">A straightforward buying journey with no customer account required.</h2>

                    <p>
                        Customers choose their tickets, enter the details needed for the booking and continue
                        to secure payment. They do not need to create another account just to attend your event.
                    </p>

                    <ul class="mkt-check-list">
                        <li>No buyer account required</li>
                        <li>Clear ticket prices before checkout</li>
                        <li>Secure payment through Stripe</li>
                        <li>Ticket confirmation sent after the order is confirmed</li>
                    </ul>
                </div>

                <div class="mkt-feature-split__visual" aria-hidden="true">
                    <div class="mkt-product-card">
                        <div class="mkt-product-card__header">
                            <span>Checkout</span>
                            <span>Secure</span>
                        </div>

                        <div class="mkt-product-card__body">
                            <div class="mkt-ui-order">
                                <div>
                                    <span>1 × Adults (16+)</span>
                                    <strong>£6.00</strong>
                                </div>

                                <div>
                                    <span>1 × Child (under 16s)</span>
                                    <strong>£2.00</strong>
                                </div>

                                <div class="mkt-ui-order__total">
                                    <span>Total</span>
                                    <strong>£8.00</strong>
                                </div>
                            </div>

                            <div class="mkt-ui-button">
                                Continue to payment
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="mkt-section mkt-section--alt" aria-labelledby="step-checkin">
        <div class="mkt-container">
            <div class="mkt-feature-split">
                <div class="mkt-feature-split__content">
                    <span class="mkt-step-label">05 · Event day</span>

                    <h2 id="step-checkin">Check guests in from your phone.</h2>

                    <p>
                        Open the check-in tools on a supported phone, scan the QR code on a customer's ticket
                        and see the result immediately. No specialist ticket scanner is required.
                    </p>

                    <ul class="mkt-check-list">
                        <li>QR ticket scanning</li>
                        <li>Clear valid and already-scanned states</li>
                        <li>Attendee and order lookup</li>
                        <li>Designed for event-day use</li>
                    </ul>
                </div>

                <div class="mkt-feature-split__visual">
                    <div class="mkt-phone" aria-label="Example ticket check-in result">
                        <div class="mkt-phone__screen">
                            <span class="mkt-phone__eyebrow">Ticket check-in</span>

                            <div class="mkt-checkin-result mkt-checkin-result--success">
                                <span class="mkt-checkin-result__icon" aria-hidden="true">✓</span>
                                <strong>Ticket valid</strong>
                                <span>Ready to check in</span>
                            </div>

                            <div class="mkt-checkin-ticket">
                                <strong>Annual Bonfire 2026</strong>
                                <span>Adult (16+)</span>
                            </div>

                            <div class="mkt-ui-button">
                                Check in
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="mkt-section" aria-labelledby="step-manage">
        <div class="mkt-container">
            <div class="mkt-feature-split mkt-feature-split--reverse">
                <div class="mkt-feature-split__content">
                    <span class="mkt-step-label">06 · Manage</span>

                    <h2 id="step-manage">Keep the event lifecycle in one organiser dashboard.</h2>

                    <p>
                        Before, during and after the event, your dashboard gives you one place to manage the
                        operational side of ticketing.
                    </p>

                    <ul class="mkt-check-list">
                        <li>Review ticket sales and confirmed orders</li>
                        <li>Manage customers and attendees</li>
                        <li>Access event and order information</li>
                        <li>View payment and payout information</li>
                    </ul>
                </div>

                <div class="mkt-feature-split__visual" aria-hidden="true">
                    <div class="mkt-dashboard-preview">
                        <div class="mkt-dashboard-preview__top">
                            <span>Event dashboard</span>
                            <span class="mkt-ui-status">Live</span>
                        </div>

                        <div class="mkt-dashboard-preview__stats">
                            <div>
                                <span>Tickets sold</span>
                                <strong>184</strong>
                            </div>

                            <div>
                                <span>Orders</span>
                                <strong>126</strong>
                            </div>

                            <div>
                                <span>Checked in</span>
                                <strong>121</strong>
                            </div>
                        </div>

                        <div class="mkt-dashboard-preview__rows">
                            <div>
                                <span>Events</span>
                                <span>Manage →</span>
                            </div>
                            <div>
                                <span>Orders</span>
                                <span>View →</span>
                            </div>
                            <div>
                                <span>Customers</span>
                                <span>View →</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="mkt-section mkt-section--compact mkt-section--alt" aria-labelledby="how-faq">
        <div class="mkt-container mkt-container--narrow">
            <div class="mkt-section__header">
                <span class="mkt-eyebrow">Common questions</span>
                <h2 id="how-faq">A few things organisers usually want to know.</h2>
            </div>

            <div class="mkt-faq">
                <details class="mkt-faq__item">
                    <summary>Do customers need to create an account?</summary>
                    <div class="mkt-faq__answer">
                        <p>
                            No. Customers can choose tickets and complete their booking without creating
                            an Events by CK Enterprises UK account.
                        </p>
                    </div>
                </details>

                <details class="mkt-faq__item">
                    <summary>Where does the money from ticket sales go?</summary>
                    <div class="mkt-faq__answer">
                        <p>
                            Paid ticket transactions are processed through the Stripe account connected
                            to your organisation.
                        </p>
                    </div>
                </details>

                <details class="mkt-faq__item">
                    <summary>Do I need specialist equipment to scan tickets?</summary>
                    <div class="mkt-faq__answer">
                        <p>
                            No. The check-in tools are designed to work from a supported modern phone,
                            so you do not need dedicated barcode-scanning hardware.
                        </p>
                    </div>
                </details>

                <details class="mkt-faq__item">
                    <summary>Can I run free events?</summary>
                    <div class="mkt-faq__answer">
                        <p>
                            Yes. You can create free ticket types as well as paid tickets.
                        </p>
                    </div>
                </details>

                <details class="mkt-faq__item">
                    <summary>Can I manage more than one event?</summary>
                    <div class="mkt-faq__answer">
                        <p>
                            Yes. Your organisation dashboard is designed to keep your events, orders,
                            customers and event-day tools together.
                        </p>
                    </div>
                </details>
            </div>
        </div>
    </section>

    <section class="mkt-cta">
        <div class="mkt-container mkt-cta__inner">
            <div>
                <span class="mkt-eyebrow mkt-eyebrow--on-dark">Ready to start?</span>
                <h2>Set up your first event.</h2>
                <p>
                    Create your organisation, connect Stripe and start building your event.
                </p>
            </div>

            <div class="mkt-cta__actions">
                <a class="mkt-btn mkt-btn--on-dark" href="{{ url('/register') }}">
                    Get started
                </a>

                <a class="mkt-btn mkt-btn--link-on-dark" href="{{ route('features') }}">
                    View features →
                </a>
            </div>
        </div>
    </section>
@endsection