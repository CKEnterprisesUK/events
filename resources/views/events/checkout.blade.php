@extends('layouts.app')

@section('title', 'Checkout · ' . $event->name)

@section('main_class', 'event-main')

{{-- Match the event and storefront pages: no marketing header, slim promo
     footer only. --}}
@section('chrome', 'minimal')

@php
    $currency = $company->currency ?? 'GBP';
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? '';
    $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);

    $isFreeOrder = $totalMinor === 0;
    $showFeeRow = $feeHandlingMode === \App\Models\Company::FEE_MODE_PASS_ON;
@endphp

@push('head')
    @if ($branding->hasPrimaryColour())
        <style>:root { --brand: {{ $branding->primaryColour }}; }</style>
    @endif
@endpush

@section('content')
    <article class="checkout-page">
        <div class="checkout-page__head">
            <a class="checkout-back"
               href="{{ route('event.page', ['companySlug' => $company->slug, 'event' => $event->id]) }}">
                &larr; Back to tickets
            </a>
            <h1 class="checkout-page__title">Checkout</h1>
            <p class="checkout-page__event">{{ $event->name }}</p>
        </div>

        @if ($errors->any())
            <div class="alert-error" role="alert">
                <p>We couldn't continue your order:</p>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="checkout-page__grid">
            {{-- Read-only summary of the tickets chosen on the event page. --}}
            <aside class="checkout-order" aria-label="Order summary">
                <h2 class="checkout-order__title">Your order</h2>
                <ul class="checkout-order__lines">
                    @foreach ($lines as $line)
                        <li class="checkout-order__line">
                            <span class="checkout-order__qty">{{ $line['quantity'] }}&times;</span>
                            <span class="checkout-order__name">{{ $line['name'] }}</span>
                            <span class="checkout-order__amount">
                                @if ($line['is_free'])
                                    Free
                                @else
                                    {{ $money($line['line_total_minor']) }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                <div class="checkout-order__totals">
                    @if ($showFeeRow)
                        <div class="checkout-order__row">
                            <span>Subtotal</span>
                            <span>{{ $money($subtotalMinor) }}</span>
                        </div>
                        <div class="checkout-order__row">
                            <span>Booking fee</span>
                            <span>{{ $money($feeMinor) }}</span>
                        </div>
                    @endif
                    <div class="checkout-order__row checkout-order__row--total">
                        <span>Total</span>
                        <span>{{ $money($totalMinor) }}</span>
                    </div>
                </div>

                <a class="checkout-order__edit"
                   href="{{ route('event.page', ['companySlug' => $company->slug, 'event' => $event->id]) }}">
                    Change tickets
                </a>
            </aside>

            {{-- Customer details, consents and payment. Posts the same items the
                 event page selected (carried through hidden inputs) to the order
                 creation endpoint, which reserves capacity and starts Stripe. --}}
            <div class="checkout-details">
                <form method="POST"
                      action="{{ route('event.checkout', ['companySlug' => $company->slug, 'event' => $event->id]) }}"
                      class="checkout-form" id="checkout-form">
                    @csrf

                    {{-- Carry the resolved selection forward unchanged. Indices
                         stay contiguous, as CheckoutController::store expects. --}}
                    @foreach ($lines as $i => $line)
                        <input type="hidden" name="items[{{ $i }}][ticket_type_id]" value="{{ $line['ticket_type_id'] }}">
                        <input type="hidden" name="items[{{ $i }}][quantity]" value="{{ $line['quantity'] }}">
                    @endforeach

                    <section class="checkout-section">
                        <h2 class="checkout-section__title">Your details</h2>
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
                    </section>

                    {{-- Organiser's custom questions (0–3), asked once per order.
                         Rendered by type: free text, single-choice radios, or a
                         number field. Answers are keyed by question id so the
                         controller can match them back to each question. --}}
                    @if ($questions->isNotEmpty())
                        <section class="checkout-section">
                            <h2 class="checkout-section__title">A few questions</h2>
                            @foreach ($questions as $question)
                                @php $qKey = 'questions.' . $question->id; @endphp
                                <div class="field">
                                    <label for="question_{{ $question->id }}">
                                        {{ $question->label }}
                                        @unless ($question->required)
                                            <span class="muted">(optional)</span>
                                        @endunless
                                    </label>

                                    @if ($question->isSelect())
                                        <div class="checkout-choices" role="group" aria-label="{{ $question->label }}">
                                            @foreach ($question->choices() as $choiceIndex => $choice)
                                                <label class="consent">
                                                    <input type="radio"
                                                           name="questions[{{ $question->id }}]"
                                                           id="question_{{ $question->id }}{{ $choiceIndex === 0 ? '' : '_' . $choiceIndex }}"
                                                           value="{{ $choice }}"
                                                           {{ old($qKey) === $choice ? 'checked' : '' }}
                                                           {{ $question->required ? 'required' : '' }}>
                                                    <span>{{ $choice }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @elseif ($question->isNumber())
                                        <input type="number" step="any"
                                               name="questions[{{ $question->id }}]"
                                               id="question_{{ $question->id }}"
                                               value="{{ old($qKey) }}"
                                               {{ $question->required ? 'required' : '' }}>
                                    @else
                                        <input type="text" maxlength="1000"
                                               name="questions[{{ $question->id }}]"
                                               id="question_{{ $question->id }}"
                                               value="{{ old($qKey) }}"
                                               {{ $question->required ? 'required' : '' }}>
                                    @endif

                                    @error($qKey)<p class="error">{{ $message }}</p>@enderror
                                </div>
                            @endforeach
                        </section>
                    @endif

                    <section class="checkout-section">
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
                    </section>

                    <button type="submit" class="btn btn-block checkout-submit">
                        @if ($isFreeOrder)
                            Complete booking
                        @else
                            Continue to payment
                        @endif
                    </button>
                    <p class="checkout-note checkout-note--secure">
                        @if ($isFreeOrder)
                            This is a free booking. You won't be charged.
                        @else
                            Secure checkout. You won't be charged until the payment step.
                        @endif
                    </p>
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
            </div>
        </div>
    </article>
@endsection

@push('scripts')
<script>
    (function () {
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
