@extends('layouts.app')

@section('title', 'Payment not completed')

@section('chrome', 'minimal')

@push('head')
    @if ($branding->hasPrimaryColour())
        <style>:root { --brand: {{ $branding->primaryColour }}; }</style>
    @endif
@endpush

@section('content')
    <section class="checkout-return checkout-cancel" data-order-status="{{ $order->status }}">
        <div class="checkout-return__icon" aria-hidden="true">!</div>
        <h1>Payment not completed</h1>

        <p class="checkout-cancelled">
            Your payment was cancelled or didn't complete, so this order wasn't
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
            Try again
        </a>
    </section>
@endsection
