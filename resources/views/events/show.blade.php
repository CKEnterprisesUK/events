@extends('layouts.app')

@section('title', $event->name)

@section('main_class', 'event-main')

{{-- Match the storefront and checkout pages: no marketing header, and only the
     slim promo footer instead of the full site footer. --}}
@section('chrome', 'minimal')

@php
    $currency = $company->currency ?? 'GBP';
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? '';
    $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);

    $hasOnSale = $ticketTypes->contains(fn ($t) => $t['on_sale'] && ! $t['sold_out']);

    // Lowest purchasable price across on-sale, non-sold-out ticket types,
    // for the mobile "Book now" bar. Null when nothing is on sale.
    $bookNowFromMinor = $hasOnSale
        ? $ticketTypes->filter(fn ($t) => $t['on_sale'] && ! $t['sold_out'])->min(fn ($t) => $t['price_minor'])
        : null;

    // Organiser identity + best contact route for a customer who needs help,
    // mirroring the checkout success page: dedicated support inbox first, then
    // the general company email, then phone.
    $sellerName = $company->trading_name ?: ($company->name ?: $company->legal_name);
    $supportEmail = $company->support_email ?: $company->email;
    $supportPhone = $company->phone;
    $supportWebsite = $company->website;
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

    @php
        $seoCanonical = route('event.page', [
            'companySlug' => $company->slug,
            'event' => $event->getKey(),
        ]);
        $seoImage = $branding->hasPoster()
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($branding->posterPath)
            : ($branding->hasLogo()
                ? \Illuminate\Support\Facades\Storage::disk('public')->url($branding->logoPath)
                : null);
        $seoDescription = $event->description
            ?: ($event->venue
                ? $event->name.' at '.$event->venue.'. Book tickets now.'
                : 'Book tickets for '.$event->name.'.');
    @endphp
    @include('partials.seo-meta', [
        'seoTitle' => $event->name.' · '.$sellerName,
        'seoDescription' => $seoDescription,
        'seoCanonical' => $seoCanonical,
        'seoImage' => $seoImage,
        'seoType' => 'article',
    ])

    {{-- schema.org Event structured data for rich results / Google Events.
         Only fields we can populate authoritatively are emitted (no endDate
         column exists, so it is omitted). Offers reflect the current lowest
         purchasable price and whether anything is on sale right now. --}}
    @php
        $eventLd = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->name,
            'description' => $event->description
                ? trim(preg_replace('/\s+/', ' ', strip_tags($event->description)))
                : null,
            'url' => $seoCanonical,
            'startDate' => $event->starts_at?->toIso8601String(),
            'image' => $seoImage,
            'eventStatus' => $event->cancelled_at
                ? 'https://schema.org/EventCancelled'
                : 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => $event->isOnline()
                ? 'https://schema.org/OnlineEventAttendanceMode'
                : 'https://schema.org/OfflineEventAttendanceMode',
            'organizer' => array_filter([
                '@type' => 'Organization',
                'name' => $sellerName,
                'url' => $company->website ?: route('storefront', ['companySlug' => $company->slug]),
            ], fn ($v) => $v !== null && $v !== ''),
            'location' => $event->isOnline()
                ? array_filter([
                    '@type' => 'VirtualLocation',
                    'url' => $seoCanonical,
                ], fn ($v) => $v !== null && $v !== '')
                : array_filter([
                    '@type' => 'Place',
                    'name' => $event->venue ?: null,
                    'address' => $event->address ?: $event->venue ?: null,
                ], fn ($v) => $v !== null && $v !== ''),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        if ($bookNowFromMinor !== null) {
            $eventLd['offers'] = array_filter([
                '@type' => 'Offer',
                'price' => number_format($bookNowFromMinor / 100, 2, '.', ''),
                'priceCurrency' => $currency,
                'availability' => $hasOnSale
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/SoldOut',
                'url' => $seoCanonical,
            ], fn ($v) => $v !== null && $v !== '');
        }
    @endphp
    <script type="application/ld+json">
        {!! json_encode($eventLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
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

                {{-- Event sponsors: the same logos optionally printed on the
                     e-ticket, surfaced publicly on the event page with each
                     sponsor's name, bio and website link shown inline by default
                     (no click needed) so sponsors get full visibility. --}}
                @php
                    $eventSponsors = $event->sponsors
                        ->map(fn ($s) => ['path' => $s->image_path, 'name' => $s->name, 'website' => $s->website_url, 'bio' => $s->bio])
                        ->filter(fn ($sponsor) => filled($sponsor['path']))
                        ->values();
                @endphp
                @if ($eventSponsors->isNotEmpty())
                    <section class="event-sponsors store-sponsors store-sponsors--expanded">
                        <h2>Our sponsors</h2>
                        <div class="store-sponsors__grid store-sponsors__grid--expanded">
                            @foreach ($eventSponsors as $sponsor)
                                @include('storefront.partials.sponsor', ['sponsor' => $sponsor, 'expanded' => true])
                            @endforeach
                        </div>
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

            {{-- Ticket selection only. Choosing quantities and pressing
                 "Continue" carries the selection to the dedicated checkout step,
                 where the customer enters their details and pays. --}}
            <aside class="event-checkout">
                <div class="checkout-card">
                    <h2 class="checkout-card__title">Get tickets</h2>

                    @if (session('checkout_error'))
                        <div class="alert-error" role="alert">
                            <p>{{ session('checkout_error') }}</p>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="alert-error" role="alert">
                            <ul>
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

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
                              action="{{ route('event.checkout.show', ['companySlug' => $company->slug, 'event' => $event->id]) }}"
                              class="ticket-select-form" id="ticket-select-form">
                            @csrf

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

                            <div class="checkout-summary" data-checkout-summary hidden
                                 data-fee-mode="{{ $feeHandlingMode }}"
                                 data-fee-percent-hundredths="{{ $feePercentHundredths }}">
                                @if ($feeHandlingMode === \App\Models\Company::FEE_MODE_PASS_ON)
                                    <div class="checkout-summary__row">
                                        <span class="checkout-summary__label">Subtotal</span>
                                        <span class="checkout-summary__value" data-summary-subtotal>{{ $symbol }}0.00</span>
                                    </div>
                                    <div class="checkout-summary__row" data-summary-fee-row>
                                        <span class="checkout-summary__label">Booking fee</span>
                                        <span class="checkout-summary__value" data-summary-fee>{{ $symbol }}0.00</span>
                                    </div>
                                    <div class="checkout-summary__row checkout-summary__row--total">
                                        <span class="checkout-summary__label">Total</span>
                                        <span class="checkout-summary__value" data-summary-total>{{ $symbol }}0.00</span>
                                    </div>
                                @else
                                    <div class="checkout-summary__row checkout-summary__row--total">
                                        <span class="checkout-summary__label">Total</span>
                                        <span class="checkout-summary__value" data-summary-total>{{ $symbol }}0.00</span>
                                    </div>
                                @endif
                            </div>

                            <button type="submit" class="btn btn-block checkout-submit" data-checkout-submit disabled>
                                Continue
                            </button>
                            <p class="checkout-note checkout-note--secure">You'll enter your details and pay on the next step.</p>
                        </form>
                    @endif
                </div>
            </aside>
        </div>

        {{-- Support & lost tickets, moved to the bottom of the page under softer
             wording. A customer who has already bought can ask for their tickets
             to be resent, and find how to reach the organiser. The resend form
             intentionally never reveals whether an email has an order — it always
             returns the same neutral confirmation, so it can't be used to probe
             who has booked. --}}
        <section class="event-support" id="support">
            <h2>Already booked?</h2>

            <div class="event-support__grid">
                <div class="event-support__resend">
                    <h3>Retrieve or resend your tickets</h3>
                    <p>
                        Enter the email address you used when booking and we'll
                        resend your tickets for this event.
                    </p>

                    @if (session('resend_status'))
                        <p class="event-support__notice" role="status">
                            {{ session('resend_status') }}
                        </p>
                    @endif

                    <form method="POST"
                          action="{{ route('event.tickets.resend', ['companySlug' => $company->slug, 'event' => $event->id]) }}"
                          class="event-support__form">
                        @csrf

                        <div class="field">
                            <label for="resend_email">Email address</label>
                            <input type="email" name="email" id="resend_email"
                                   value="{{ old('email') }}" maxlength="254" required
                                   autocomplete="email" inputmode="email">
                            @error('email')<p class="error">{{ $message }}</p>@enderror
                        </div>

                        <button type="submit" class="btn">Resend my tickets</button>
                    </form>

                    <p class="event-support__hint">
                        If we don't find anything and you don't receive an email,
                        please check your spam or junk folder, then contact
                        {{ $sellerName }} using the details here.
                    </p>
                </div>

                <div class="event-support__contact">
                    <h3>Contact {{ $sellerName }}</h3>
                    @if ($supportEmail || $supportPhone || $supportWebsite)
                        <ul class="event-support__contacts">
                            @if ($supportEmail)
                                <li>
                                    <span class="event-support__ico" aria-hidden="true">✉</span>
                                    <a href="mailto:{{ $supportEmail }}?subject={{ rawurlencode('Ticket support: '.$event->name) }}">{{ $supportEmail }}</a>
                                </li>
                            @endif
                            @if ($supportPhone)
                                <li>
                                    <span class="event-support__ico" aria-hidden="true">☎</span>
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $supportPhone) }}">{{ $supportPhone }}</a>
                                </li>
                            @endif
                            @if ($supportWebsite)
                                <li>
                                    <span class="event-support__ico" aria-hidden="true">🌐</span>
                                    <a href="{{ $supportWebsite }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://#', '', rtrim($supportWebsite, '/')) }}</a>
                                </li>
                            @endif
                        </ul>
                    @else
                        <p class="muted">Contact the organiser where you first heard about this event.</p>
                    @endif
                </div>
            </div>

            {{-- Report abuse: an unobtrusive disclosure at the very bottom of
                 every event page. Filing a report creates an abuse-category
                 support ticket for the platform operators (Events by CK
                 Enterprises), separate from contacting the organiser above. The
                 response is deliberately neutral — we never confirm what action,
                 if any, a specific report triggered. --}}
            <details class="event-support__report" id="report" {{ ($errors->any() && old('_report')) ? 'open' : '' }}>
                <summary>Report this event</summary>

                <p class="event-support__report-intro">
                    Think this event is fraudulent, misleading or breaks the rules?
                    Let the Events by CK Enterprises team know and we'll look into it.
                    This goes to us, not the organiser.
                </p>

                @if (session('report_status'))
                    <p class="event-support__notice" role="status">
                        {{ session('report_status') }}
                    </p>
                @endif

                <form method="POST"
                      action="{{ route('event.report-abuse', ['companySlug' => $company->slug, 'event' => $event->id]) }}"
                      class="event-support__form">
                    @csrf
                    <input type="hidden" name="_report" value="1">

                    <div class="field">
                        <label for="report_reason">What's wrong?</label>
                        <select name="reason" id="report_reason" required>
                            <option value="" disabled @selected(! old('reason'))>Choose a reason…</option>
                            @foreach (['Fraud or a scam', 'Misleading or fake event', 'Impersonation of a person or brand', 'Offensive or prohibited content', 'Something else'] as $reasonOption)
                                <option value="{{ $reasonOption }}" @selected(old('reason') === $reasonOption)>{{ $reasonOption }}</option>
                            @endforeach
                        </select>
                        @error('reason')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="report_details">Tell us more</label>
                        <textarea name="details" id="report_details" rows="4"
                                  minlength="10" maxlength="5000" required
                                  placeholder="What made you report this event?">{{ old('details') }}</textarea>
                        @error('details')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="report_email">Your email (optional)</label>
                        <input type="email" name="reporter_email" id="report_email"
                               value="{{ old('reporter_email') }}" maxlength="254"
                               autocomplete="email" inputmode="email"
                               placeholder="So we can follow up if needed">
                        @error('reporter_email')<p class="error">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit" class="btn btn--secondary">Submit report</button>
                </form>
            </details>
        </section>

        {{-- Mobile "Book now" bar: a sibling of .event-layout (never inside the
             sticky aside) so it can be fixed to the viewport bottom on small
             screens. Suppressed entirely when there are no tickets or nothing
             is on sale, so it never advertises a price that leads nowhere. --}}
        @if ($hasOnSale && $bookNowFromMinor !== null)
            <div class="book-now-bar" data-book-now-bar>
                <div class="book-now-bar__price">
                    <span class="book-now-bar__label">From</span>
                    <span class="book-now-bar__amount">{{ $money($bookNowFromMinor) }}</span>
                </div>
                <button type="button" class="btn book-now-bar__cta"
                        data-book-now
                        aria-label="Book now, jump to ticket selection">
                    Book now
                </button>
            </div>
        @endif
    </article>
@endsection

@push('scripts')
<script>
    (function () {
        var form = document.getElementById('ticket-select-form');
        if (!form) return;

        var summary = form.querySelector('[data-checkout-summary]');
        var summarySubtotal = form.querySelector('[data-summary-subtotal]');
        var summaryFee = form.querySelector('[data-summary-fee]');
        var summaryTotal = form.querySelector('[data-summary-total]');
        var submit = form.querySelector('[data-checkout-submit]');
        var symbol = @json($symbol);

        // Fee context mirrors the server's FeeCalculationService so the total
        // previewed here matches the amount charged at Stripe. In Pass_On mode
        // the customer pays the booking fee on top of the subtotal.
        var feeMode = summary ? summary.getAttribute('data-fee-mode') : 'absorb';
        var feePercentHundredths = summary
            ? (parseInt(summary.getAttribute('data-fee-percent-hundredths'), 10) || 0)
            : 0;

        function money(minor) {
            return symbol + (minor / 100).toFixed(2);
        }

        // Application_Fee = round-half-up(subtotal × percentHundredths / 10000),
        // clamped to [0, subtotal] — the same integer arithmetic the server uses
        // so the previewed total never disagrees with Stripe by a penny.
        function bookingFee(subtotal) {
            if (feeMode !== 'pass_on' || subtotal <= 0 || feePercentHundredths <= 0) {
                return 0;
            }
            var numerator = subtotal * feePercentHundredths;
            var fee = Math.floor(numerator / 10000);
            if ((numerator % 10000) * 2 >= 10000) { fee += 1; }
            if (fee > subtotal) { fee = subtotal; }
            return fee;
        }

        function recalc() {
            var subtotal = 0;
            var count = 0;
            form.querySelectorAll('[data-qty-input]').forEach(function (input) {
                var qty = parseInt(input.value, 10) || 0;
                var price = parseInt(input.getAttribute('data-price'), 10) || 0;
                subtotal += qty * price;
                count += qty;
                var row = input.closest('.ticket-type');
                if (row) { row.classList.toggle('is-selected', qty > 0); }
            });
            var fee = bookingFee(subtotal);
            var total = subtotal + fee;
            if (summarySubtotal) { summarySubtotal.textContent = money(subtotal); }
            if (summaryFee) { summaryFee.textContent = money(fee); }
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
    })();

    // Mobile "Book now" bar: scrolls/focuses the ticket selection and hides
    // itself once the continue control (or the card) is in view. Self-contained
    // so it does not depend on the ticket-select script above.
    (function () {
        var bar = document.querySelector('[data-book-now-bar]');
        if (!bar) return;                               // suppressed cases: no-op
        var cta = bar.querySelector('[data-book-now]');
        var card = document.querySelector('.checkout-card');
        var submit = document.querySelector('[data-checkout-submit]');

        // (a) Book now → smooth-scroll + move focus to the ticket card.
        if (cta && card) {
            cta.addEventListener('click', function () {
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                // Make the card programmatically focusable, then focus for AT/keyboard.
                if (!card.hasAttribute('tabindex')) card.setAttribute('tabindex', '-1');
                card.focus({ preventScroll: true });
            });
        }

        // (b) Hide the bar while the continue control (or card) is in view.
        var target = submit || card;
        if (target && 'IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) { bar.hidden = e.isIntersecting; });
            }, { threshold: 0.01 });
            io.observe(target);
        } else if (target) {
            // Scroll fallback for browsers without IntersectionObserver.
            var onScroll = function () {
                var r = target.getBoundingClientRect();
                bar.hidden = r.top < window.innerHeight && r.bottom > 0;
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            window.addEventListener('resize', onScroll);
            onScroll();
        }
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
