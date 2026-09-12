@extends('layouts.app')

@section('title', 'Pricing · Events by CK Enterprises UK')
@section('main_class', 'mkt')

@include('marketing._meta', [
    'metaTitle' => 'Transparent event ticketing pricing',
    'description' => 'Transparent event ticketing pricing with no setup fee or monthly subscription. You only pay a platform fee when you sell a paid ticket.',
    'canonical' => route('pricing'),
])

@php
    // Live Global_Fee_Percent from the DB (via PricingViewData). Whole numbers
    // render without trailing decimals (5, not 5.00). The calculator's data-*
    // attributes carry the raw values; its money maths mirrors
    // FeeCalculationService for a display estimate only.
    $feeDisplay = number_format($feePercent, $feePercent == (int) $feePercent ? 0 : 2);
    $stripePctDisplay = rtrim(rtrim(number_format($stripeFeePercent, 2), '0'), '.');
    $stripeFixedDisplay = number_format($stripeFeeFixedMinor / 100, 2);

    // Seed values so the result reads sensibly before any JS runs (progressive
    // enhancement): £10 × 100 tickets, organisation pays the fee.
    $seedPrice = 10.0;
    $seedQty = 100;
    $seedFeeEach = round($seedPrice * ($feePercent / 100), 2);
    $seedFeeTotal = round($seedFeeEach * $seedQty, 2);
    $seedGross = $seedPrice * $seedQty;
    $seedStripe = round($seedGross * ($stripeFeePercent / 100) + ($stripeFeeFixedMinor / 100) * $seedQty, 2);
    $seedPayout = max(0, round($seedGross - $seedFeeTotal - $seedStripe, 2));
@endphp

@section('content')
    <section class="mkt-section">
        <div class="mkt-container mkt-pricing">
            {{-- Left: proposition. Payout + simple model lead; the % stays clearly
                 visible but is not the largest element on the page. --}}
            <div>
                <span class="mkt-eyebrow">Transparent pricing</span>
                <h1>Simple pricing. Pay only when you sell.</h1>
                <p class="mkt-hero__lead" style="color: var(--mkt-body); margin-top: 1rem;">
                    No setup costs or monthly subscription. You only pay a platform fee when you sell a paid ticket.
                </p>

                <div class="mkt-price-line"><span>{{ $feeDisplay }}% per paid ticket</span></div>
                <p class="mkt-price-sub">+ Stripe payment processing</p>

                <ul class="mkt-checks" style="margin-top: 1.6rem;">
                    <li><span><strong>No setup fee</strong><span>Start selling without an upfront cost.</span></span></li>
                    <li><span><strong>No monthly subscription</strong><span>You only pay when you sell paid tickets.</span></span></li>
                    <li><span><strong>Direct Stripe payouts</strong><span>Ticket revenue goes directly to your connected Stripe account.</span></span></li>
                </ul>

                <p class="mkt-note">
                    Charities and community organisations can
                    <a href="https://ckenterprises.co.uk/#contact" target="_blank" rel="noopener">contact us about tailored pricing</a>.
                </p>

                <div class="mkt-callout">
                    <strong>Free events?</strong>
                    No platform fee on free tickets.
                </div>
            </div>

            {{-- Right: lightweight calculator, payout-first. --}}
            <div
                class="mkt-calc"
                id="pricing-calc"
                data-fee="{{ $feePercent }}"
                data-stripe-fee-pct="{{ $stripeFeePercent }}"
                data-stripe-fee-fixed="{{ $stripeFeeFixedMinor }}"
            >
                <p class="mkt-calc__title">Pricing calculator</p>

                <div class="mkt-calc__row2">
                    <div class="mkt-field">
                        <label for="calc-price">Ticket price</label>
                        <div class="mkt-input-wrap">
                            <span class="mkt-prefix" aria-hidden="true">£</span>
                            <input type="number" id="calc-price" class="has-prefix" min="0" step="0.50"
                                   value="{{ number_format($seedPrice, 2, '.', '') }}" inputmode="decimal">
                        </div>
                    </div>
                    <div class="mkt-field">
                        <label for="calc-qty">Expected ticket sales</label>
                        <input type="number" id="calc-qty" min="0" step="1" value="{{ $seedQty }}" inputmode="numeric">
                    </div>
                </div>

                <div class="mkt-seg">
                    <span class="mkt-seg__label" id="fee-choice-label">Who covers the platform fee?</span>
                    <div class="mkt-seg__control" role="group" aria-labelledby="fee-choice-label">
                        <button type="button" id="mode-org" aria-pressed="true">Organisation pays</button>
                        <button type="button" id="mode-buyer" aria-pressed="false">Ticket buyer pays</button>
                    </div>
                </div>

                <div class="mkt-payout" aria-live="polite">
                    <div class="mkt-payout__label">Estimated payout</div>
                    <div class="mkt-payout__value">£<span id="result-payout">{{ number_format($seedPayout, 2) }}</span></div>
                    <div class="mkt-payout__from">from £<span id="result-gross">{{ number_format($seedGross, 2) }}</span> in ticket sales</div>

                    <div class="mkt-fees">
                        <div class="mkt-fees__row"><span>Platform fee</span><span>£<span id="result-fee-total">{{ number_format($seedFeeTotal, 2) }}</span></span></div>
                        <div class="mkt-fees__row"><span>Stripe estimate</span><span>£<span id="result-stripe-fee">{{ number_format($seedStripe, 2) }}</span></span></div>
                        <div class="mkt-fees__row mkt-fees__row--total"><span>Total estimated fees</span><span>£<span id="result-fees-total">{{ number_format($seedFeeTotal + $seedStripe, 2) }}</span></span></div>
                    </div>
                </div>

                <details class="mkt-disclosure">
                    <summary>How this is calculated</summary>
                    <p>
                        Stripe's payment-processing fee is estimated at {{ $stripePctDisplay }}% + £{{ $stripeFixedDisplay }}
                        per transaction. This is an estimate for planning — your exact Stripe fee is confirmed on each
                        settled payment. The platform fee is {{ $feeDisplay }}% of paid ticket sales; free tickets carry no platform fee.
                    </p>
                    <p id="buyer-note" hidden>
                        When the ticket buyer covers the platform fee, it is added to the ticket price and shown to the
                        customer as part of the total price from the first price display — never added later at checkout.
                    </p>
                </details>
            </div>
        </div>
    </section>

    <section class="mkt-cta">
        <div class="mkt-container mkt-cta__inner">
            <div>
                <h2>Ready when you are.</h2>
                <p>Create your organisation and only pay when you sell a paid ticket.</p>
            </div>
            <div class="mkt-cta__actions">
                <a class="mkt-btn mkt-btn--on-dark" href="{{ url('/register') }}">Get started</a>
                <a class="mkt-btn mkt-btn--link-on-dark" href="{{ route('features') }}">See features →</a>
            </div>
        </div>
    </section>

    @push('scripts')
        <script>
            (function () {
                var root = document.getElementById('pricing-calc');
                if (!root) { return; }

                var feePct = Math.max(0, parseFloat(root.getAttribute('data-fee')) || 0);
                // Configurable Stripe-fee estimate (percent + fixed pence) from the
                // DB. Used to show an approximate Stripe cut so the payout reflects
                // the real net, not one that ignores Stripe. Fixed part is pence.
                var stripePct = Math.max(0, parseFloat(root.getAttribute('data-stripe-fee-pct')) || 0);
                var stripeFixed = Math.max(0, (parseInt(root.getAttribute('data-stripe-fee-fixed'), 10) || 0) / 100);

                var priceEl = document.getElementById('calc-price');
                var qtyEl = document.getElementById('calc-qty');
                var orgBtn = document.getElementById('mode-org');
                var buyerBtn = document.getElementById('mode-buyer');
                var buyerNote = document.getElementById('buyer-note');

                var outPayout = document.getElementById('result-payout');
                var outGross = document.getElementById('result-gross');
                var outFeeTotal = document.getElementById('result-fee-total');
                var outStripe = document.getElementById('result-stripe-fee');
                var outFeesTotal = document.getElementById('result-fees-total');

                var buyerPays = false;

                function money(value) {
                    return value.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }

                function recalc() {
                    var price = Math.max(0, parseFloat(priceEl.value) || 0);
                    var qty = Math.max(0, parseInt(qtyEl.value, 10) || 0);

                    // Platform fee per ticket = round-half-up(price × fee%) — a
                    // display mirror of FeeCalculationService's per-order maths.
                    var feeEach = Math.round(price * (feePct / 100) * 100) / 100;
                    var feeTotal = Math.round(feeEach * qty * 100) / 100;

                    // "from … in ticket sales" is what the buyer is charged.
                    // Organisation pays: buyer pays the ticket price; the fee comes
                    // out of the organisation's take. Buyer pays: the fee is added
                    // on top, so the buyer is charged price + fee.
                    var chargedEach = buyerPays ? (price + feeEach) : price;
                    var chargedTotal = Math.round(chargedEach * qty * 100) / 100;

                    // Stripe estimate is a percent of what the buyer is actually
                    // charged, plus the fixed part per transaction.
                    var stripe = (qty > 0 && chargedTotal > 0)
                        ? Math.round((chargedTotal * (stripePct / 100) + stripeFixed * qty) * 100) / 100
                        : 0;

                    // Organiser payout = charged total − platform fee − Stripe.
                    var payout = Math.max(0, Math.round((chargedTotal - feeTotal - stripe) * 100) / 100);

                    outPayout.textContent = money(payout);
                    outGross.textContent = money(chargedTotal);
                    outFeeTotal.textContent = money(feeTotal);
                    outStripe.textContent = money(stripe);
                    outFeesTotal.textContent = money(Math.round((feeTotal + stripe) * 100) / 100);
                }

                function setMode(isBuyer) {
                    buyerPays = isBuyer;
                    orgBtn.setAttribute('aria-pressed', isBuyer ? 'false' : 'true');
                    buyerBtn.setAttribute('aria-pressed', isBuyer ? 'true' : 'false');
                    if (buyerNote) { buyerNote.hidden = !isBuyer; }
                    recalc();
                }

                [priceEl, qtyEl].forEach(function (el) { el.addEventListener('input', recalc); });
                orgBtn.addEventListener('click', function () { setMode(false); });
                buyerBtn.addEventListener('click', function () { setMode(true); });

                recalc();
            })();
        </script>
    @endpush
@endsection
