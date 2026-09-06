@extends('layouts.app')

@section('title', $event->name)

@section('main_class', 'event-main')

@php
    $currency = $company->currency ?? 'GBP';
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? '';
    $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);

    $hasOnSale = $ticketTypes->contains(fn ($t) => $t['on_sale'] && ! $t['sold_out']);
@endphp

@push('head')
    @if ($branding->hasPrimaryColour())
        <style>:root { --brand: {{ $branding->primaryColour }}; }</style>
    @endif
    @if ($event->isInPerson() && $event->hasCoordinates())
        <link rel="stylesheet"
              href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
              integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
              crossorigin="">
    @endif
@endpush

@section('content')
    <article class="event-page">
        {{-- Poster / hero image. Event poster overrides the company hero. --}}
        <header class="event-hero {{ $branding->hasPoster() ? 'event-hero--poster' : 'event-hero--plain' }}">
            @if ($branding->hasPoster())
                <img class="event-hero__img"
                     src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->posterPath) }}"
                     alt="{{ $event->name }}">
                <div class="event-hero__scrim"></div>
            @endif

            <div class="event-hero__content">
                @if ($branding->hasLogo())
                    <img class="event-hero__logo"
                         src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath) }}"
                         alt="{{ $company->name }}">
                @endif
                <h1>{{ $event->name }}</h1>
                <div class="event-hero__meta">
                    @if ($event->starts_at)
                        <span class="event-meta-item event-meta-item--date">
                            <time datetime="{{ $event->starts_at->toIso8601String() }}">
                                {{ $event->starts_at->format('D j M Y, H:i') }}
                            </time>
                        </span>
                    @endif
                    @if ($event->venue)
                        <span class="event-meta-item event-meta-item--venue">{{ $event->venue }}</span>
                    @endif
                </div>
            </div>
        </header>

        <div class="event-layout">
            <div class="event-primary">
                @if ($event->description)
                    <section class="event-about">
                        <h2>About this event</h2>
                        <div class="event-description">{{ $event->description }}</div>
                    </section>
                @endif

                {{-- Event location: read-only map + Google Maps link for
                     in-person events with coordinates; email-joining notice for
                     online events. (Requirements 4.6, 4.7, 4.9) --}}
                @if ($event->isInPerson() && $event->hasCoordinates())
                    <section class="event-location">
                        <h2>Location</h2>
                        @if ($event->venue)
                            <p class="event-location__venue">{{ $event->venue }}</p>
                        @endif
                        <div id="public-map" role="img"
                             aria-label="Map showing the event location"
                             style="height:300px"></div>
                        <p class="event-location__actions">
                            <a class="btn btn-outline" target="_blank" rel="noopener"
                               href="https://www.google.com/maps/dir/?api=1&destination={{ $event->latitude }},{{ $event->longitude }}">Open in Google Maps</a>
                        </p>
                    </section>
                @elseif ($event->isOnline())
                    <section class="event-location">
                        <h2>Location</h2>
                        <p class="event-online-notice">This is an online event. Joining information will be sent to you by email.</p>
                    </section>
                @endif

                {{-- Show the company logo in the body when it differs from the
                     event's own logo, so the organiser is always credited. --}}
                @if ($branding->hasEventLogo() && $branding->hasCompanyLogo() && $branding->eventLogoPath !== $branding->companyLogoPath)
                    <section class="event-organiser">
                        <span class="event-organiser__label">Presented by</span>
                        <img class="event-organiser__logo"
                             src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($branding->companyLogoPath) }}"
                             alt="{{ $company->name }}">
                        <span class="event-organiser__name">{{ $company->name }}</span>
                    </section>
                @endif
            </div>

            {{-- Ticket selection + checkout --}}
            <aside class="event-checkout">
                <div class="checkout-card">
                    <h2 class="checkout-card__title">Get tickets</h2>

                    @if ($ticketTypes->isEmpty())
                        <p class="empty">No tickets are available for this event yet.</p>
                    @elseif (! $hasOnSale)
                        <ul class="ticket-type-list">
                            @foreach ($ticketTypes as $type)
                                @include('events._ticket_row', ['type' => $type, 'money' => $money, 'selectable' => false])
                            @endforeach
                        </ul>
                        <p class="checkout-note">Tickets aren't on sale right now. Please check back soon.</p>
                    @else
                        <form method="POST"
                              action="{{ route('event.checkout', ['companySlug' => $company->slug, 'event' => $event->id]) }}"
                              class="checkout-form" id="checkout-form">
                            @csrf

                            @if ($errors->any())
                                <div class="alert-error" role="alert">
                                    <p>We couldn't start your order:</p>
                                    <ul>
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <ul class="ticket-type-list">
                                @php($index = 0)
                                @foreach ($ticketTypes as $type)
                                    @php($selectable = $type['on_sale'] && ! $type['sold_out'])
                                    @include('events._ticket_row', ['type' => $type, 'money' => $money, 'selectable' => $selectable, 'index' => $index])
                                    @if ($selectable)
                                        @php($index++)
                                    @endif
                                @endforeach
                            </ul>

                            <div class="checkout-summary" data-checkout-summary hidden>
                                <span class="checkout-summary__label">Subtotal</span>
                                <span class="checkout-summary__value" data-summary-total>{{ $symbol }}0.00</span>
                            </div>

                            <div class="checkout-fields">
                                <div class="field">
                                    <label for="customer_name">Your name</label>
                                    <input type="text" name="customer_name" id="customer_name"
                                           value="{{ old('customer_name') }}" maxlength="200" required autocomplete="name">
                                    @error('customer_name')<p class="error">{{ $message }}</p>@enderror
                                </div>

                                <div class="field">
                                    <label for="customer_email">Email address</label>
                                    <input type="email" name="customer_email" id="customer_email"
                                           value="{{ old('customer_email') }}" maxlength="254" required autocomplete="email">
                                    <span class="field-hint">We'll email your tickets here.</span>
                                    @error('customer_email')<p class="error">{{ $message }}</p>@enderror
                                </div>
                            </div>

                            <div class="checkout-consents">
                                <label class="consent">
                                    <input type="hidden" name="consents[terms]" value="0">
                                    <input type="checkbox" name="consents[terms]" value="1" required
                                           {{ old('consents.terms') ? 'checked' : '' }}>
                                    <span>
                                        I accept the
                                        @if ($branding->hasTerms())
                                            <button type="button" class="linklike" data-open-terms>terms &amp; conditions</button>.
                                        @else
                                            terms &amp; conditions.
                                        @endif
                                    </span>
                                </label>
                                @error('consents.terms')<p class="error">{{ $message }}</p>@enderror

                                <label class="consent">
                                    <input type="hidden" name="consents[privacy]" value="0">
                                    <input type="checkbox" name="consents[privacy]" value="1" required
                                           {{ old('consents.privacy') ? 'checked' : '' }}>
                                    <span>
                                        I agree to the processing of my details to fulfil this order
                                        @if ($branding->hasPrivacy())
                                            , as described in the <button type="button" class="linklike" data-open-privacy>privacy notice</button>.
                                        @else
                                            .
                                        @endif
                                    </span>
                                </label>
                                @error('consents.privacy')<p class="error">{{ $message }}</p>@enderror

                                <label class="consent consent--optional">
                                    <input type="hidden" name="consents[marketing]" value="0">
                                    <input type="checkbox" name="consents[marketing]" value="1"
                                           {{ old('consents.marketing') ? 'checked' : '' }}>
                                    <span>Keep me updated about future events (optional).</span>
                                </label>
                            </div>

                            <button type="submit" class="btn btn-block checkout-submit" data-checkout-submit disabled>
                                Continue to payment
                            </button>
                            <p class="checkout-note checkout-note--secure">Secure checkout. You won't be charged until the next step.</p>
                        </form>

                        @if ($branding->hasTerms())
                            <div class="terms-modal" data-terms-modal hidden>
                                <div class="terms-modal__backdrop" data-close-terms></div>
                                <div class="terms-modal__panel" role="dialog" aria-modal="true" aria-label="Terms and conditions">
                                    <div class="terms-modal__head">
                                        <h3>Terms &amp; conditions</h3>
                                        <button type="button" class="terms-modal__close" data-close-terms aria-label="Close">&times;</button>
                                    </div>
                                    <div class="terms-modal__body">{{ $branding->termsText }}</div>
                                </div>
                            </div>
                        @endif

                        @if ($branding->hasPrivacy())
                            <div class="terms-modal" data-privacy-modal hidden>
                                <div class="terms-modal__backdrop" data-close-privacy></div>
                                <div class="terms-modal__panel" role="dialog" aria-modal="true" aria-label="Privacy notice">
                                    <div class="terms-modal__head">
                                        <h3>Privacy notice</h3>
                                        <button type="button" class="terms-modal__close" data-close-privacy aria-label="Close">&times;</button>
                                    </div>
                                    <div class="terms-modal__body">{{ $branding->privacyText }}</div>
                                </div>
                            </div>
                        @endif
                    @endif
                </div>
            </aside>
        </div>
    </article>
@endsection

@push('scripts')
<script>
    (function () {
        var form = document.getElementById('checkout-form');
        if (!form) return;

        var summary = form.querySelector('[data-checkout-summary]');
        var summaryTotal = form.querySelector('[data-summary-total]');
        var submit = form.querySelector('[data-checkout-submit]');
        var symbol = @json($symbol);

        function money(minor) {
            return symbol + (minor / 100).toFixed(2);
        }

        function recalc() {
            var total = 0;
            var count = 0;
            form.querySelectorAll('[data-qty-input]').forEach(function (input) {
                var qty = parseInt(input.value, 10) || 0;
                var price = parseInt(input.getAttribute('data-price'), 10) || 0;
                total += qty * price;
                count += qty;
                var row = input.closest('.ticket-type');
                if (row) { row.classList.toggle('is-selected', qty > 0); }
            });
            if (summaryTotal) { summaryTotal.textContent = money(total); }
            if (summary) { summary.hidden = count === 0; }
            if (submit) { submit.disabled = count === 0; }
        }

        // Quantity steppers
        form.querySelectorAll('[data-step]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = form.querySelector('#' + btn.getAttribute('data-target'));
                if (!input) return;
                var step = parseInt(btn.getAttribute('data-step'), 10);
                var max = parseInt(input.getAttribute('max'), 10);
                var next = (parseInt(input.value, 10) || 0) + step;
                if (next < 0) next = 0;
                if (!isNaN(max) && next > max) next = max;
                input.value = next;
                recalc();
            });
        });

        form.querySelectorAll('[data-qty-input]').forEach(function (input) {
            input.addEventListener('input', recalc);
            input.addEventListener('change', recalc);
        });

        recalc();

        // Legal modals: Terms & Conditions and Privacy notice, each shown on
        // request from its checkout consent line.
        function wireModal(modalSelector, openSelector, closeSelector) {
            var modal = document.querySelector(modalSelector);
            if (!modal) return;
            document.querySelectorAll(openSelector).forEach(function (el) {
                el.addEventListener('click', function () { modal.hidden = false; });
            });
            modal.querySelectorAll(closeSelector).forEach(function (el) {
                el.addEventListener('click', function () { modal.hidden = true; });
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { modal.hidden = true; }
            });
        }

        wireModal('[data-terms-modal]', '[data-open-terms]', '[data-close-terms]');
        wireModal('[data-privacy-modal]', '[data-open-privacy]', '[data-close-privacy]');
    })();
</script>
@endpush

@if ($event->isInPerson() && $event->hasCoordinates())
@push('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>
<script>
    (function () {
        // Read-only public map: guard on the container and Leaflet being present.
        var mapEl = document.getElementById('public-map');
        if (!mapEl || !window.L) return;

        var lat = parseFloat(@json((string) $event->latitude));
        var lng = parseFloat(@json((string) $event->longitude));
        if (isNaN(lat) || isNaN(lng)) return;

        var map = L.map(mapEl).setView([lat, lng], 15);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(map);

        // Non-draggable marker for a view-only pin.
        L.marker([lat, lng], { draggable: false }).addTo(map);

        // Keep the map sized correctly once laid out.
        window.setTimeout(function () { map.invalidateSize(); }, 0);
    })();
</script>
@endpush
@endif
