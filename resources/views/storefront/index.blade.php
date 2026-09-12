@extends('layouts.storefront')

@section('title', $company->name)

@section('brand-style')
    @if ($branding->hasPrimaryColour())
        <style>:root { --brand: {{ $branding->primaryColour }}; }</style>
    @endif
@endsection

@push('head')
    @php
        $seoCanonical = route('storefront', ['companySlug' => $company->slug]);
        $seoImage = $branding->hasPoster()
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($branding->posterPath)
            : ($branding->hasLogo()
                ? \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath)
                : null);
    @endphp
    @include('partials.seo-meta', [
        'seoTitle' => $company->name,
        'seoDescription' => $company->about_text
            ?: 'Book tickets for events by '.$company->name.'.',
        'seoCanonical' => $seoCanonical,
        'seoImage' => $seoImage,
        'seoType' => 'website',
    ])

    {{-- schema.org Organization so search engines understand the storefront
         represents an event organiser, with its social profiles as sameAs. --}}
    <script type="application/ld+json">
        {!! json_encode(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $company->name,
            'url' => $seoCanonical,
            'logo' => $branding->hasLogo()
                ? \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath)
                : null,
            'description' => $company->about_text ?: null,
            'sameAs' => array_values(array_filter([
                $company->website,
                $company->facebook_url,
                $company->instagram_url,
                $company->x_url,
                $company->linkedin_url,
            ])),
        ], fn ($v) => $v !== null && $v !== [] && $v !== ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endpush

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
            // Gather every sponsor across the listed events, keeping each unique
            // logo (by image path) once so the storefront shows a single
            // combined strip while preserving each sponsor's name/website/bio.
            $sponsors = $events
                ->flatMap(fn ($event) => $event['sponsors'] ?? [])
                ->filter(fn ($sponsor) => filled($sponsor['path'] ?? null))
                ->unique('path')
                ->values();
        @endphp

        @if ($sponsors->isNotEmpty())
            <section class="store-section store-sponsors">
                <h2>Our sponsors</h2>
                <div class="store-sponsors__grid">
                    @foreach ($sponsors as $sponsor)
                        @include('storefront.partials.sponsor', ['sponsor' => $sponsor])
                    @endforeach
                </div>
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

            $socialIcons = [
                'Website' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
                'Facebook' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12z"/></svg>',
                'Instagram' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
                'X' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24h-6.66l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.45-6.231zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>',
                'LinkedIn' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.42v1.56h.05a3.75 3.75 0 0 1 3.37-1.85c3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13zM7.12 20.45H3.55V9h3.57v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0z"/></svg>',
            ];
        @endphp

        @if (! empty($socialLinks))
            <section class="store-section store-links">
                <ul class="store-links__list" aria-label="Follow {{ $company->name }}">
                    @foreach ($socialLinks as $label => $url)
                        <li>
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer nofollow" title="{{ $label }}">
                                <span class="store-links__icon">{!! $socialIcons[$label] ?? '' !!}</span>
                                <span class="store-links__label">{{ $label }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </main>
@endsection
