@extends('layouts.app')

@section('title', 'Order '.$order->order_reference)

@section('chrome', 'minimal')

@php
    $currency = $company->currency ?? 'GBP';
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? '';
    $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);

    // Who the customer just bought from, and the best contact for them if the
    // tickets don't arrive. Prefer a dedicated support inbox, then the general
    // company email, then the phone number.
    $sellerName = $company->trading_name ?: ($company->name ?: $company->legal_name);
    $supportEmail = $company->support_email ?: $company->email;
    $supportPhone = $company->phone;
@endphp

@push('head')
    @if ($branding->hasPrimaryColour())
        <style>:root { --brand: {{ $branding->primaryColour }}; }</style>
    @endif
@endpush

@section('content')
    <section class="checkout-return checkout-success" data-order-status="{{ $order->status }}">
        <div class="checkout-return__icon" aria-hidden="true">✓</div>
        <h1>Thank you{{ $order->customer_name ? ', '.\Illuminate\Support\Str::of($order->customer_name)->before(' ') : '' }}</h1>

        @if ($awaitingConfirmation)
            {{-- Paid Order: the browser return is not authoritative. The Order is
                 confirmed only by the payment webhook, so show it as pending. --}}
            <p class="checkout-pending">
                Your payment is being confirmed. We'll email your tickets to
                <strong>{{ $order->customer_email }}</strong> once confirmation is complete.
            </p>
        @else
            <p class="checkout-confirmed">
                Your order is confirmed. We'll email your tickets to
                <strong>{{ $order->customer_email }}</strong>.
            </p>
        @endif

        @if ($event->isOnline())
            <p class="checkout-online-notice">
                This is an online event. Joining information will be sent to
                <strong>{{ $order->customer_email }}</strong> by email.
            </p>
        @endif

        <div class="checkout-seller">
            <span class="checkout-seller__label">You bought from</span>
            <span class="checkout-seller__name">{{ $sellerName }}</span>
            @if ($company->website)
                <a class="checkout-seller__link" href="{{ $company->website }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://#', '', rtrim($company->website, '/')) }}</a>
            @endif
        </div>

        <dl class="order-summary">
            <dt>Order reference</dt>
            <dd class="order-reference">{{ $order->order_reference }}</dd>

            <dt>Event</dt>
            <dd>{{ $event->name }}</dd>

            <dt>Ticket subtotal</dt>
            <dd>{{ $money($order->ticket_subtotal_minor) }}</dd>

            @if ($order->booking_fee_minor > 0)
                <dt>Booking fee</dt>
                <dd>{{ $money($order->booking_fee_minor) }}</dd>
            @endif

            <dt>Order total</dt>
            <dd class="order-total">{{ $money($order->order_total_minor) }}</dd>
        </dl>

        <div class="checkout-help">
            <h2>Haven't got your tickets?</h2>
            <p>
                Your tickets should arrive by email within about 5 minutes. Please
                check your spam or junk folder first. If they still haven't turned up,
                get in touch with {{ $sellerName }} and quote your order reference
                <strong>{{ $order->order_reference }}</strong>.
            </p>
            @if ($supportEmail || $supportPhone)
                <ul class="checkout-help__contacts">
                    @if ($supportEmail)
                        <li>
                            <span class="checkout-help__ico" aria-hidden="true">✉</span>
                            <a href="mailto:{{ $supportEmail }}?subject={{ rawurlencode('Missing tickets: order '.$order->order_reference) }}">{{ $supportEmail }}</a>
                        </li>
                    @endif
                    @if ($supportPhone)
                        <li>
                            <span class="checkout-help__ico" aria-hidden="true">☎</span>
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $supportPhone) }}">{{ $supportPhone }}</a>
                        </li>
                    @endif
                </ul>
            @endif
        </div>

        <a class="btn btn-outline" href="{{ route('event.page', ['companySlug' => $company->slug, 'event' => $event->id]) }}">
            Back to event
        </a>
    </section>
@endsection
