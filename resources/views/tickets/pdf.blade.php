{{--
    Print-ready A4 e-ticket PDF for a confirmed Order, rendered by dompdf via
    App\Services\TicketPdfService. Every image is an inlined base64 data URI
    ($qrDataUri, $sponsorTop, $sponsorBottom, $logoDataUri) because dompdf
    renders offline and cannot resolve CID or relative asset URLs.

    Layout mirrors a classic A4 e-ticket: an optional top sponsor banner, the
    event heading with the scannable QR to its right, venue + date, a shaded
    admission bar per ticket line, the booking summary, the organiser's custom
    entry instructions, and an optional bottom sponsor banner.
--}}
@php
    $primary = $branding->hasPrimaryColour() ? $branding->primaryColour : '#111827';
    $money = fn (int $minor) => $currencySymbol . number_format($minor / 100, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Ticket {{ $order->order_reference }}</title>
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            color: #111827;
            font-size: 12px;
        }
        .sheet { padding: 32px 40px; }

        .sponsor-band { width: 100%; text-align: center; margin: 0 0 12px; }
        .sponsor-band--bottom { margin: 24px 0 0; }
        .sponsor-band img { max-width: 100%; max-height: 120px; }

        .ticket {
            border: 1px solid #e5e7eb;
            border-top: 6px solid {{ $primary }};
            border-radius: 6px;
            padding: 28px 32px;
        }

        .head { width: 100%; }
        .head td { vertical-align: top; }
        .head__title { font-size: 26px; font-weight: bold; line-height: 1.15; margin: 0; color: {{ $primary }}; }
        .head__qr { text-align: right; width: 170px; }
        .head__qr img { width: 150px; height: 150px; }
        .head__ref { font-family: DejaVu Sans Mono, monospace; font-size: 13px; letter-spacing: 2px; margin-top: 4px; text-align: center; }

        .venue { margin: 14px 0 2px; font-size: 15px; }
        .datetime { font-size: 17px; font-weight: bold; margin: 6px 0 2px; }
        .datetime__sub { font-size: 12px; color: #6b7280; }

        .admission {
            border: 1px solid #111827;
            background: #f3f4f6;
            padding: 10px 14px;
            margin: 18px 0 6px;
            width: 100%;
        }
        .admission td { font-weight: bold; font-size: 15px; }
        .admission__right { text-align: right; }

        .booking { color: #374151; margin: 14px 0; }
        .booking strong { color: #111827; }

        .instructions {
            border-top: 1px solid #e5e7eb;
            margin-top: 16px;
            padding-top: 12px;
            font-size: 11px;
            color: #374151;
            line-height: 1.5;
            white-space: pre-line;
        }

        .totals { margin-top: 14px; width: 100%; border-collapse: collapse; }
        .totals td { padding: 4px 0; }
        .totals .num { text-align: right; }
        .totals .grand td { border-top: 1px solid #d1d5db; font-weight: bold; padding-top: 8px; }

        .brandmark { margin-top: 20px; text-align: center; }
        .brandmark img { max-height: 48px; }
    </style>
</head>
<body>
    <div class="sheet">
        {{-- Optional top sponsor banner (landscape). --}}
        @if ($sponsorTop)
            <div class="sponsor-band">
                <img src="{{ $sponsorTop }}" alt="Sponsor">
            </div>
        @endif

        <div class="ticket">
            <table class="head">
                <tr>
                    <td>
                        <h1 class="head__title">{{ $event->name }}</h1>
                    </td>
                    <td class="head__qr">
                        <img src="{{ $qrDataUri }}" alt="Entry QR code for order {{ $order->order_reference }}">
                        <div class="head__ref">{{ $order->order_reference }}</div>
                    </td>
                </tr>
            </table>

            @if ($event->venue)
                <div class="venue">{{ $event->venue }}</div>
            @endif
            @if ($event->address)
                <div class="datetime__sub">{{ $event->address }}</div>
            @endif

            @if ($event->starts_at)
                <div class="datetime">{{ $event->starts_at->format('l jS F Y \a\t g:iA') }}</div>
            @endif

            {{-- Admission bar per ticket line: ticket type + quantity/price. --}}
            @foreach ($lineItems as $item)
                <table class="admission">
                    <tr>
                        <td>
                            {{ $item['ticket_type'] }}:
                            {{ $item['price_minor'] === 0 ? 'Free' : $money($item['price_minor']) }}
                        </td>
                        <td class="admission__right">
                            &times; {{ $item['quantity'] }}
                        </td>
                    </tr>
                </table>
            @endforeach

            <div class="booking">
                Booked by <strong>{{ $order->customer_name }}</strong>
                @if ($order->created_at)
                    on {{ $order->created_at->format('l jS F Y') }}
                @endif
            </div>

            <table class="totals">
                <tr>
                    <td>Ticket subtotal</td>
                    <td class="num">{{ $money($order->ticket_subtotal_minor) }}</td>
                </tr>
                @if ($order->booking_fee_minor > 0)
                    <tr>
                        <td>Booking fee</td>
                        <td class="num">{{ $money($order->booking_fee_minor) }}</td>
                    </tr>
                @endif
                <tr class="grand">
                    <td>Order total</td>
                    <td class="num">{{ $money($order->order_total_minor) }}</td>
                </tr>
            </table>

            {{-- Organiser's custom entry instructions, falling back to a
                 sensible default when none are set. --}}
            <div class="instructions">
                @if (filled($event->ticket_instructions))
                    {{ $event->ticket_instructions }}
                @else
                    Entry to this event is by e-ticket, so please bring this ticket with you when you come to the venue. It is your responsibility to keep this e-ticket safe. If this e-ticket is copied it may be invalidated at the door, preventing entry to the event.
                @endif
            </div>

            @if ($logoDataUri)
                <div class="brandmark">
                    <img src="{{ $logoDataUri }}" alt="Organiser logo">
                </div>
            @endif
        </div>

        {{-- Optional bottom sponsor banner (landscape). --}}
        @if ($sponsorBottom)
            <div class="sponsor-band sponsor-band--bottom">
                <img src="{{ $sponsorBottom }}" alt="Sponsor">
            </div>
        @endif
    </div>
</body>
</html>
