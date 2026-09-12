@php
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $symbol = $symbols[$currency] ?? '';
    $money = fn (int $minor) => $symbol . number_format($minor / 100, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sales &amp; payout statement</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1a1a1a;
            font-size: 12px;
            margin: 0;
            padding: 0;
        }
        .doc { padding: 28px 32px; }
        .head { border-bottom: 2px solid #222; padding-bottom: 10px; margin-bottom: 18px; }
        .head h1 { font-size: 18px; margin: 0 0 2px; }
        .head .company { font-size: 13px; font-weight: bold; }
        .head .meta { color: #555; font-size: 11px; margin-top: 4px; }
        h2 { font-size: 13px; margin: 20px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; text-align: left; }
        .totals th { width: 60%; font-weight: normal; }
        .totals td { text-align: right; font-variant-numeric: tabular-nums; }
        .totals tr { border-bottom: 1px solid #eee; }
        .totals tr.grand { border-top: 2px solid #222; border-bottom: none; }
        .totals tr.grand th, .totals tr.grand td { font-weight: bold; padding-top: 8px; }
        .events th { background: #f4f4f4; border-bottom: 1px solid #ccc; font-size: 10px; }
        .events td { border-bottom: 1px solid #eee; font-size: 10px; }
        .events .num { text-align: right; font-variant-numeric: tabular-nums; }
        .note { color: #555; font-size: 10px; margin-top: 16px; line-height: 1.5; }
        .empty { color: #777; font-style: italic; }
    </style>
</head>
<body>
    <div class="doc">
        <div class="head">
            <div class="company">{{ $companyName }}</div>
            <h1>Sales &amp; payout statement</h1>
            <div class="meta">
                Period: {{ $rangeLabel }} &nbsp;·&nbsp; Currency: {{ $currency }}
                &nbsp;·&nbsp; Generated: {{ $generatedAt }}
            </div>
        </div>

        <h2>Company reconciliation</h2>
        <table class="totals">
            <tbody>
                <tr><th>Confirmed orders</th><td>{{ number_format($totals['orders']) }}</td></tr>
                <tr><th>Tickets sold</th><td>{{ number_format($totals['tickets_sold']) }}</td></tr>
                <tr><th>Gross sales</th><td>{{ $money($totals['gross_sales_minor']) }}</td></tr>
                <tr><th>Booking fees collected</th><td>{{ $money($totals['booking_fees_minor']) }}</td></tr>
                <tr><th>Platform fees</th><td>{{ $money($totals['application_fees_minor']) }}</td></tr>
                <tr><th>Total collected</th><td>{{ $money($totals['order_total_minor']) }}</td></tr>
                <tr><th>Net after platform fee</th><td>{{ $money($totals['net_to_company_minor']) }}</td></tr>
                <tr><th>Stripe processing fees</th><td>{{ $money($totals['stripe_fees_minor']) }}</td></tr>
                <tr class="grand"><th>Net payout to bank</th><td>{{ $money($totals['net_payout_minor']) }}</td></tr>
            </tbody>
        </table>

        <h2>Per-event breakdown</h2>
        @if (empty($perEvent))
            <p class="empty">No confirmed sales in this period.</p>
        @else
            <table class="events">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th class="num">Orders</th>
                        <th class="num">Tickets</th>
                        <th class="num">Gross</th>
                        <th class="num">Platform fee</th>
                        <th class="num">Collected</th>
                        <th class="num">Stripe fee</th>
                        <th class="num">Net payout</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($perEvent as $row)
                        <tr>
                            <td>{{ $row['event_name'] }}</td>
                            <td class="num">{{ number_format($row['orders']) }}</td>
                            <td class="num">{{ number_format($row['tickets_sold']) }}</td>
                            <td class="num">{{ $money($row['gross_sales_minor']) }}</td>
                            <td class="num">{{ $money($row['application_fees_minor']) }}</td>
                            <td class="num">{{ $money($row['order_total_minor']) }}</td>
                            <td class="num">{{ $money($row['stripe_fees_minor']) }}</td>
                            <td class="num">{{ $money($row['net_payout_minor']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <p class="note">
            Funds settle directly to your connected Stripe account. The platform fee is deducted at the
            point of sale; Stripe's own card-processing fee is taken inside your Stripe account. The net
            payout subtracts both, so it reflects what reaches your bank. Stripe fees are read from each
            settled charge; a very recent sale may show a zero fee until Stripe finalises it.
        </p>
    </div>
</body>
</html>
