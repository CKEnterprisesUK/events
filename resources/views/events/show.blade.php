@extends('layouts.app')

@section('title', $event->name)

@push('head')
    @if ($branding->hasPrimaryColour())
        <style>:root { --brand: {{ $branding->primaryColour }}; }</style>
    @endif
@endpush

@section('content')
    <section class="event-header">
        @if ($branding->hasLogo())
            <img class="event-logo"
                 src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath) }}"
                 alt="logo">
        @endif
        <h1>{{ $event->name }}</h1>

        @if ($event->venue)
            <p class="event-venue">{{ $event->venue }}</p>
        @endif
        @if ($event->starts_at)
            <p class="event-date">
                <time datetime="{{ $event->starts_at->toIso8601String() }}">
                    {{ $event->starts_at->format('D j M Y, H:i') }}
                </time>
            </p>
        @endif
        @if ($event->description)
            <div class="event-description">{{ $event->description }}</div>
        @endif
    </section>

    <section class="ticket-types">
        <h2>Tickets</h2>

        @if ($ticketTypes->isEmpty())
            <p class="empty">No tickets are available for this event yet.</p>
        @else
            <ul class="ticket-type-list">
                @foreach ($ticketTypes as $type)
                    <li class="ticket-type" data-sale-state="{{ $type['sale_state'] }}">
                        <span class="ticket-type-name">{{ $type['name'] }}</span>

                        <span class="ticket-type-price">
                            @if ($type['is_free'])
                                Free
                            @else
                                {{ number_format($type['price_minor'] / 100, 2) }}
                            @endif
                        </span>

                        <span class="ticket-type-availability">
                            @if ($type['sold_out'])
                                Sold out
                            @else
                                {{ $type['available'] }} remaining
                            @endif
                        </span>

                        <span class="ticket-type-sale-state">
                            @switch($type['sale_state'])
                                @case('on_sale')
                                    On sale
                                    @break
                                @case('not_yet')
                                    Not yet on sale
                                    @break
                                @case('ended')
                                    Sale ended
                                    @break
                                @default
                                    Unavailable
                            @endswitch
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
