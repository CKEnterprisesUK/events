@extends('layouts.storefront')

@section('title', $company->name)

@section('brand-style')
    @if ($branding->hasPrimaryColour())
        <style>:root { --brand: {{ $branding->primaryColour }}; }</style>
    @endif
@endsection

@section('content')
    <header class="store-header {{ $branding->hasPoster() ? 'store-header--poster' : '' }}">
        @if ($branding->hasPoster())
            <div class="store-hero">
                <img class="store-hero__img"
                     src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->posterPath) }}"
                     alt="{{ $company->name }}">
                <div class="store-hero__overlay"></div>
            </div>
        @endif

        <div class="store-header__inner">
            @if ($branding->hasLogo())
                <img class="store-logo"
                     src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath) }}"
                     alt="{{ $company->name }} logo">
            @endif
            <h1>{{ $company->name }}</h1>
        </div>
    </header>

    <main class="store-body">
        <section class="store-section">
            <h2>Upcoming events</h2>

            @if ($events->isEmpty())
                <p class="empty">There are no events on sale right now. Please check back soon.</p>
            @else
                <ul class="event-list">
                    @foreach ($events as $event)
                        @php $poster = $event['poster_path'] ?? null; @endphp
                        <li class="event-list-item {{ $poster ? 'event-list-item--poster' : '' }}">
                            <a class="event-card" href="{{ url($company->slug . '/' . $event['id']) }}">
                                @if ($poster)
                                    <span class="event-card__thumb">
                                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($poster) }}"
                                             alt="" loading="lazy">
                                    </span>
                                @endif
                                <span class="event-card__body">
                                    <span class="event-card__title">{{ $event['name'] }}</span>
                                    @if (! empty($event['starts_at']))
                                        <time class="event-date" datetime="{{ $event['starts_at'] }}">
                                            {{ \Illuminate\Support\Carbon::parse($event['starts_at'])->format('D j M Y, H:i') }}
                                        </time>
                                    @endif
                                    @if (! empty($event['venue']))
                                        <span class="event-venue">{{ $event['venue'] }}</span>
                                    @endif
                                    <span class="event-card__cta">View tickets &rarr;</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if (filled($company->about_text))
            <section class="store-section store-about">
                <h2>About {{ $company->name }}</h2>
                <p class="store-about__text">{!! nl2br(e($company->about_text)) !!}</p>
            </section>
        @endif

        @php
            $socialLinks = array_filter([
                'Website' => $company->website,
                'Facebook' => $company->facebook_url,
                'Instagram' => $company->instagram_url,
                'X' => $company->x_url,
                'LinkedIn' => $company->linkedin_url,
            ]);
        @endphp

        @if (! empty($socialLinks))
            <section class="store-section store-links">
                <ul class="store-links__list" aria-label="Follow {{ $company->name }}">
                    @foreach ($socialLinks as $label => $url)
                        <li>
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer nofollow">{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </main>
@endsection
