@extends('layouts.dashboard')

@section('title', 'Reports & Payouts')

@section('page_title', 'Reports & Payouts')

@section('content')
    @php
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $symbol = $symbols[$currency] ?? '';
        $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);
    @endphp

    <section>
        <div class="page-header">
            <div class="page-header__text">
                <h1>Reports &amp; Payouts</h1>
                <p class="page-header__desc">
                    Read-only view of your organisation's realised sales from paid and confirmed orders,
                    shown in {{ $currency }} ({{ $symbol !== '' ? $symbol : $currency }}).
                </p>
            </div>
            <div class="page-header__actions">
                {{-- Export carries the current date range through so the file
                     matches what's on screen. Secondary actions — outline, not
                     the primary blue. --}}
                @php $exportQuery = array_filter(['from' => $from ?? null, 'to' => $to ?? null]); @endphp
                <a class="btn btn-outline" href="{{ route('dashboard.reports.export', $exportQuery) }}" download>Export CSV</a>
                <a class="btn btn-outline" href="{{ route('dashboard.reports.export-pdf', $exportQuery) }}">Export PDF</a>
            </div>
        </div>

        {{-- Date-range filter. Both bounds are optional and inclusive; leaving
             them blank shows all realised sales. Submitting reloads the report,
             the totals, the per-event breakdown and the export links for the
             chosen period. --}}
        <form method="GET" action="{{ route('dashboard.reports.index') }}" class="report-filter">
            <div class="field">
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="{{ $from ?? '' }}">
            </div>
            <div class="field">
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="{{ $to ?? '' }}">
            </div>
            <div class="report-filter__actions">
                <button type="submit" class="btn btn-sm">Apply</button>
                @if (! empty($from) || ! empty($to))
                    <a class="btn btn-sm btn-outline" href="{{ route('dashboard.reports.index') }}">Clear</a>
                @endif
            </div>
        </form>

        @if (! empty($from) || ! empty($to))
            <p class="panel__note" data-report-period>
                Showing
                @if (! empty($from) && ! empty($to))
                    {{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}
                @elseif (! empty($from))
                    from {{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }}
                @else
                    up to {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}
                @endif
            </p>
        @endif

        {{-- Small summary row for the headline figures. Not oversized cards. --}}
        <div class="summary-row">
            <div class="metric-card">
                <span class="metric-card__label">Confirmed orders</span>
                <span class="metric-card__value">{{ number_format($totals['orders']) }}</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Tickets sold</span>
                <span class="metric-card__value">{{ number_format($totals['tickets_sold']) }}</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Gross sales</span>
                <span class="metric-card__value">{{ $money($totals['gross_sales_minor']) }}</span>
            </div>
            <div class="metric-card">
                <span class="metric-card__label">Net payout</span>
                <span class="metric-card__value">{{ $money($totals['net_payout_minor']) }}</span>
            </div>
        </div>

        {{-- Full financial reconciliation — the detail matters, so it stays a
             table (with the fee/collected breakdown), not a wall of cards. --}}
        <div class="panel">
            <div class="panel__head"><h2>Company reconciliation</h2></div>
            <table class="data-table">
                <tbody>
                    <tr>
                        <th scope="row">Gross sales</th>
                        <td class="num" data-metric="gross_sales_minor">{{ $money($totals['gross_sales_minor']) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Booking fees</th>
                        <td class="num" data-metric="booking_fees_minor">{{ $money($totals['booking_fees_minor']) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Platform fees</th>
                        <td class="num" data-metric="application_fees_minor">{{ $money($totals['application_fees_minor']) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Total collected</th>
                        <td class="num" data-metric="order_total_minor">{{ $money($totals['order_total_minor']) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Net after platform fee</th>
                        <td class="num" data-metric="net_to_company_minor">{{ $money($totals['net_to_company_minor']) }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Stripe processing fees</th>
                        <td class="num" data-metric="stripe_fees_minor">{{ $money($totals['stripe_fees_minor']) }}</td>
                    </tr>
                    <tr class="data-table__total">
                        <th scope="row">Net payout to bank</th>
                        <td class="num" data-metric="net_payout_minor">{{ $money($totals['net_payout_minor']) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="panel__note">
                Funds settle directly to your connected Stripe account. The platform fee is deducted at
                the point of sale; Stripe's own card-processing fee is taken inside your Stripe account.
                The net payout subtracts both, so it reflects what actually reaches your bank. Stripe fees
                are read from each settled charge, so a very recent sale may show a fee of zero until Stripe
                finalises it (usually within minutes).
            </p>
        </div>

        <div class="panel">
            <div class="panel__head"><h2>Per-event breakdown</h2></div>

            @if (empty($perEvent))
                <p class="table-empty-filter">No confirmed sales yet.</p>
            @else
                {{-- Desktop: the full financial table, horizontally scrollable
                     within its panel if the viewport is narrow, with the event
                     name column readable. --}}
                <div class="table-scroll only-desktop">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Event</th>
                                <th scope="col" class="num">Orders</th>
                                <th scope="col" class="num">Tickets sold</th>
                                <th scope="col" class="num">Gross sales</th>
                                <th scope="col" class="num">Booking fees</th>
                                <th scope="col" class="num">Platform fees</th>
                                <th scope="col" class="num">Total collected</th>
                                <th scope="col" class="num">Stripe fees</th>
                                <th scope="col" class="num">Net payout</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($perEvent as $row)
                                <tr data-event-id="{{ $row['event_id'] }}">
                                    <td class="cell-strong">{{ $row['event_name'] }}</td>
                                    <td class="num" data-metric="orders">{{ number_format($row['orders']) }}</td>
                                    <td class="num" data-metric="tickets_sold">{{ number_format($row['tickets_sold']) }}</td>
                                    <td class="num" data-metric="gross_sales_minor">{{ $money($row['gross_sales_minor']) }}</td>
                                    <td class="num" data-metric="booking_fees_minor">{{ $money($row['booking_fees_minor']) }}</td>
                                    <td class="num" data-metric="application_fees_minor">{{ $money($row['application_fees_minor']) }}</td>
                                    <td class="num" data-metric="order_total_minor">{{ $money($row['order_total_minor']) }}</td>
                                    <td class="num" data-metric="stripe_fees_minor">{{ $money($row['stripe_fees_minor']) }}</td>
                                    <td class="num" data-metric="net_payout_minor">{{ $money($row['net_payout_minor']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile: each event as a structured record instead of a
                     horizontally-scrolling financial table. --}}
                <div class="only-mobile record-list" style="padding: 1rem 1.25rem;">
                    @foreach ($perEvent as $row)
                        <div class="record-card record-card--facts" data-event-id="{{ $row['event_id'] }}">
                            <div class="record-card__body" style="width:100%;">
                                <span class="record-card__title">{{ $row['event_name'] }}</span>
                                <dl class="record-facts">
                                    <dt>Orders</dt><dd>{{ number_format($row['orders']) }}</dd>
                                    <dt>Tickets sold</dt><dd>{{ number_format($row['tickets_sold']) }}</dd>
                                    <dt>Gross sales</dt><dd>{{ $money($row['gross_sales_minor']) }}</dd>
                                    <dt>Booking fees</dt><dd>{{ $money($row['booking_fees_minor']) }}</dd>
                                    <dt>Platform fees</dt><dd>{{ $money($row['application_fees_minor']) }}</dd>
                                    <dt>Total collected</dt><dd>{{ $money($row['order_total_minor']) }}</dd>
                                    <dt>Stripe fees</dt><dd>{{ $money($row['stripe_fees_minor']) }}</dd>
                                    <dt>Net payout</dt><dd>{{ $money($row['net_payout_minor']) }}</dd>
                                </dl>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endsection
