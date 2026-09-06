@extends('layouts.app')

@section('title', $company->name)

@push('head')
    @if ($branding->hasPrimaryColour())
        <style>:root { --brand: {{ $branding->primaryColour }}; }</style>
    @endif
@endpush

@section('content')
    <section class="storefront-header">
        @if ($branding->hasLogo())
            <img class="storefront-logo"
                 src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath) }}"
                 alt="{{ $company->name }} logo">
        @endif
        <h1>{{ $company->name }}</h1>
    </section>

    <section class="storefront-events">
        <h2>Upcoming events</h2>

        @if ($events->isEmpty())
            <p class="empty">There are no events on sale right now. Please check back soon.</p>
        @else
            <ul class="event-list">
                @foreach ($events as $event)
                    <li class="event-list-item">
                        <a href="{{ url($company->slug . '/' . $event['id']) }}">
                            {{ $event['name'] }}
                        </a>
                        @if (! empty($event['venue']))
                            <span class="event-venue">{{ $event['venue'] }}</span>
                        @endif
                        @if (! empty($event['starts_at']))
                            <time class="event-date" datetime="{{ $event['starts_at'] }}">
                                {{ \Illuminate\Support\Carbon::parse($event['starts_at'])->format('D j M Y, H:i') }}
                            </time>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
