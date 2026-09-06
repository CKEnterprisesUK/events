@extends('layouts.app')

@section('title', 'Order '.$order->order_reference)

@php
    $currency = $company->currency ?? 'GBP';
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? '';
    $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);
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

        <a class="btn btn-outline" href="{{ route('event.page', ['companySlug' => $company->slug, 'event' => $event->id]) }}">
            Back to event
        </a>
    </section>
@endsection
