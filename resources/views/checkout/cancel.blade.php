@extends('layouts.app')

@section('title', 'Payment not completed')

@section('content')
    <section class="checkout-return checkout-cancel" data-order-status="{{ $order->status }}">
        <h1>Payment not completed</h1>

        <p class="checkout-cancelled">
            Your payment was cancelled or did not complete, so this order was not
            placed and the reserved tickets have been released. You can start again
            from the event page.
        </p>

        <dl class="order-summary">
            <dt>Order reference</dt>
            <dd class="order-reference">{{ $order->order_reference }}</dd>

            <dt>Event</dt>
            <dd>{{ $event->name }}</dd>
        </dl>

        <a class="btn" href="{{ route('event.page', ['companySlug' => $company->slug, 'event' => $event->id]) }}">
            Back to event
        </a>
    </section>
@endsection
