{{--
    Branded ticket email for a confirmed Order. Shows the effective
    Company/Event branding (logo, primary colour), the Order breakdown, and the
    single scannable QR_Code —
    a rendered PNG of the payload {Order_Reference}.HMAC(secret, Order_Reference)
    — that the attendee presents at entry. (Requirements 14.1, 14.4, 14.6)
--}}
@php
    $primary = $branding->hasPrimaryColour() ? $branding->primaryColour : '#111827';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your tickets for {{ $eventName }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
        <div style="background:#ffffff;border-radius:8px;overflow:hidden;border-top:6px solid {{ $primary }};">
            <div style="padding:24px;text-align:center;">
                @if (!empty($logoUrl))
                    <img src="{{ $logoUrl }}" alt="{{ $companyName }}" style="max-height:64px;margin-bottom:12px;">
                @endif
                <h1 style="margin:0;font-size:20px;color:{{ $primary }};">{{ $eventName }}</h1>
                <p style="margin:8px 0 0;color:#6b7280;">Your tickets are confirmed.</p>
                <p style="margin:4px 0 0;font-size:13px;color:#9ca3af;">Sold by {{ $companyName }}</p>
            </div>

            <div style="padding:0 24px 8px;">
                <p style="margin:0 0 4px;">Hi {{ $order->customer_name }},</p>
                <p style="margin:0 0 16px;color:#374151;">
                    Thanks for your order. Present the QR code below at the door for entry.
                </p>

                {{-- Order breakdown of Ticket_Types and quantities. (Requirement 14.6) --}}
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:16px;">
                    <thead>
                        <tr>
                            <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Ticket</th>
                            <th align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lineItems as $item)
                            <tr>
                                <td style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ $item['ticket_type'] }}</td>
                                <td align="right" style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ $item['quantity'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

            </div>

            {{-- The single scannable QR_Code for the Order, rendered as an inline
                 PNG (embedded via CID) of the payload
                 `{Order_Reference}.HMAC(secret, Order_Reference)`. The scanner
                 decodes the image and recomputes the HMAC to validate. The raw
                 payload is kept below as machine-readable text so clients that
                 block images can still recover the code. (Requirements 14.1,
                 14.2) --}}
            <div style="padding:16px 24px 24px;text-align:center;">
                <div style="display:inline-block;padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
                    <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">Order {{ $order->order_reference }}</div>
                    <img src="{{ $message->embedData($qrPng, 'qr-'.$order->order_reference.'.png', 'image/png') }}"
                         alt="QR code for order {{ $order->order_reference }}"
                         width="220" height="220"
                         style="display:block;margin:0 auto;width:220px;height:220px;">
                    <div data-qr-payload="{{ $qrPayload }}" style="font-family:monospace;font-size:10px;word-break:break-all;color:#9ca3af;margin-top:8px;">
                        {{ $qrPayload }}
                    </div>
                </div>
            </div>
        </div>

        <p style="text-align:center;color:#9ca3af;font-size:12px;margin-top:16px;">
            Order reference {{ $order->order_reference }}
            @if (!empty($supportEmail))
                &middot; Questions about your order? Contact
                <a href="mailto:{{ $supportEmail }}" style="color:#6b7280;">{{ $supportEmail }}</a>
            @endif
        </p>

        {{-- Platform footer. Presents the "Events by CK Enterprises" wordmark
             and a sell-your-tickets CTA (mirroring the on-site promo footer),
             then the legal notice making the Platform/Company relationship
             explicit: the sale is a contract with the selling Company; Events by
             CK Enterprises UK is only the ticketing platform it used. --}}
        <div style="margin-top:24px;padding-top:20px;border-top:1px solid #e5e7eb;text-align:center;">
            <p style="margin:0;font-size:15px;font-weight:bold;color:#111827;">
                Events by <span style="color:#0f16c4;">CK Enterprises</span>
            </p>
            <p style="margin:6px 0 12px;font-size:13px;color:#6b7280;">
                Branded ticketing and direct payouts for event organisers.
            </p>
            <a href="{{ url('/') }}"
               style="display:inline-block;padding:10px 18px;background:#0f16c4;color:#ffffff;text-decoration:none;border-radius:6px;font-size:13px;font-weight:bold;">
                Sell your tickets today
            </a>

            <p style="margin:20px 0 0;font-size:11px;line-height:1.6;color:#9ca3af;">
                {{ $companyName }} used Events by CK Enterprises UK as its ticketing platform
                to sell these tickets. Your purchase is a contract between you and
                {{ $companyName }}, who is solely responsible for this event and the
                tickets sold. Events by CK Enterprises UK provides the ticketing and
                payment technology only and is not a party to that contract, nor the
                organiser, promoter or seller of the event. Please direct any queries
                about your order or the event to
                {{ $companyName }}@if (!empty($supportEmail)) at <a href="mailto:{{ $supportEmail }}" style="color:#9ca3af;">{{ $supportEmail }}</a>@endif.
            </p>
            <p style="margin:12px 0 0;font-size:11px;color:#9ca3af;">
                &copy; {{ date('Y') }} <a href="https://ckenterprises.co.uk/" style="color:#9ca3af;">CK Enterprises UK</a>. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>
