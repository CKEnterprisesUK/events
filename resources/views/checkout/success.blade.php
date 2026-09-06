@extends('layouts.app')

@section('title', 'Order '.$order->order_reference)

@section('content')
    <section class="checkout-return checkout-success" data-order-status="{{ $order->status }}">
        <h1>Thank you</h1>

        @if ($awaitingConfirmation)
            {{-- Paid Order: the browser return is not authoritative. The Order is
                 confirmed only by the payment webhook, so show it as pending. --}}
            <p class="checkout-pending">
                Your payment is being confirmed. We'll email your tickets to
                {{ $order->customer_email }} once confirmation is complete.
            </p>
        @else
            <p class="checkout-confirmed">
                Your order is confirmed. We'll email your tickets to
                {{ $order->customer_email }}.
            </p>
        @endif

        <dl class="order-summary">
            <dt>Order reference</dt>
            <dd class="order-reference">{{ $order->order_reference }}</dd>

            <dt>Event</dt>
            <dd>{{ $event->name }}</dd>

            <dt>Ticket subtotal</dt>
            <dd>{{ number_format($order->ticket_subtotal_minor / 100, 2) }}</dd>

            @if ($order->booking_fee_minor > 0)
                <dt>Booking fee</dt>
                <dd>{{ number_format($order->booking_fee_minor / 100, 2) }}</dd>
            @endif

            <dt>Order total</dt>
            <dd class="order-total">{{ number_format($order->order_total_minor / 100, 2) }}</dd>
        </dl>

        <a class="btn" href="{{ route('event.page', ['companySlug' => $company->slug, 'event' => $event->id]) }}">
            Back to event
        </a>
    </section>
@endsection
