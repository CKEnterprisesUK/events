@extends('layouts.storefront')

@section('title', $company->name)

@section('favicon')
    @if ($branding->hasLogo())
        <link rel="icon" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath) }}">
        <link rel="apple-touch-icon" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath) }}">
    @else
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/favicon.png') }}">
    @endif
@endsection

@section('brand-style')
    @if ($branding->hasPrimaryColour())
        <style>:root { --brand: {{ $branding->primaryColour }}; }</style>
    @endif
@endsection

@section('content')
    <header class="store-header">
        @if ($branding->hasLogo())
            <img class="store-logo"
                 src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath) }}"
                 alt="{{ $company->name }} logo">
        @endif
        <h1>{{ $company->name }}</h1>
    </header>

    <main class="store-body">
        <section class="store-section">
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
    </main>
@endsection
