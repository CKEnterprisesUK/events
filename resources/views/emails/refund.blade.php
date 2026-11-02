{{--
    Branded refund confirmation email for a refunded Order. Mirrors the ticket
    email's branded shell (logo, primary colour, Platform footer) and tells the
    customer how much has been refunded and that a card refund can take up to
    7 working days to reappear in their bank account. A full refund confirms the
    order is cancelled and the tickets are void; a partial refund names the
    amount refunded now and the cumulative total refunded to date.
--}}
@php
    $primary = $branding->hasPrimaryColour() ? $branding->primaryColour : '#111827';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $fullyRefunded ? 'Your refund' : 'A partial refund' }} for {{ $eventName }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
        <div style="background:#ffffff;border-radius:8px;overflow:hidden;border-top:6px solid {{ $primary }};">
            <div style="padding:24px;text-align:center;">
                @if (!empty($logoUrl))
                    <img src="{{ $logoUrl }}" alt="{{ $companyName }}" style="max-height:64px;margin-bottom:12px;">
                @endif
                <h1 style="margin:0;font-size:20px;color:{{ $primary }};">{{ $eventName }}</h1>
                <p style="margin:8px 0 0;color:#6b7280;">
                    {{ $fullyRefunded ? 'Your order has been refunded.' : 'A partial refund has been issued.' }}
                </p>
                <p style="margin:4px 0 0;font-size:13px;color:#9ca3af;">Sold by {{ $companyName }}</p>
            </div>

            <div style="padding:0 24px 8px;">
                <p style="margin:0 0 4px;">Hi {{ $order->customer_name }},</p>
                <p style="margin:0 0 16px;color:#374151;">
                    @if ($fullyRefunded)
                        We've refunded your order <strong>{{ $order->order_reference }}</strong> for
                        {{ $eventName }}. Your tickets for this order have now been cancelled and are no
                        longer valid for entry.
                    @else
                        We've issued a partial refund on your order
                        <strong>{{ $order->order_reference }}</strong> for {{ $eventName }}.
                    @endif
                </p>

                {{-- Refund amount breakdown. --}}
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:16px;">
                    <tbody>
                        <tr>
                            <td style="padding:8px;border-bottom:1px solid #f3f4f6;color:#374151;">
                                {{ $fullyRefunded ? 'Amount refunded' : 'Refunded now' }}
                            </td>
                            <td align="right" style="padding:8px;border-bottom:1px solid #f3f4f6;font-weight:bold;color:{{ $primary }};">
                                {{ $amount }}
                            </td>
                        </tr>
                        @unless ($fullyRefunded)
                            <tr>
                                <td style="padding:8px;border-bottom:1px solid #f3f4f6;color:#6b7280;font-size:13px;">
                                    Total refunded so far
                                </td>
                                <td align="right" style="padding:8px;border-bottom:1px solid #f3f4f6;color:#6b7280;font-size:13px;">
                                    {{ $refundedTotal }}
                                </td>
                            </tr>
                        @endunless
                        <tr>
                            <td style="padding:8px;color:#6b7280;font-size:13px;">Original order total</td>
                            <td align="right" style="padding:8px;color:#6b7280;font-size:13px;">{{ $orderTotal }}</td>
                        </tr>
                    </tbody>
                </table>

                {{-- Bank-timing expectation: the refund is processed immediately
                     on our side, but settling back to the card can take time. --}}
                <div style="padding:16px 20px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;margin-bottom:16px;">
                    <div style="font-size:13px;color:#374151;">
                        &#128179; The refund has been processed back to the card used for the original
                        payment. Please allow <strong>up to 7 working days</strong> for the amount to
                        reappear in your bank account or on your card statement.
                    </div>
                </div>
            </div>
        </div>

        <p style="text-align:center;color:#9ca3af;font-size:12px;margin-top:16px;">
            Order reference {{ $order->order_reference }}
            @if (!empty($supportEmail))
                &middot; Questions about your refund? Contact
                <a href="mailto:{{ $supportEmail }}" style="color:#6b7280;">{{ $supportEmail }}</a>
            @endif
        </p>

        {{-- Platform footer, matching the ticket email: the "Events by CK
             Enterprises" wordmark, a sell-your-tickets CTA, then the legal
             notice making the Platform/Company relationship explicit. --}}
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
                {{ $companyName }}, who is solely responsible for this event and any
                refund issued. Events by CK Enterprises UK provides the ticketing and
                payment technology only and is not a party to that contract, nor the
                organiser, promoter or seller of the event. Please direct any queries
                about your order or refund to
                {{ $companyName }}@if (!empty($supportEmail)) at <a href="mailto:{{ $supportEmail }}" style="color:#9ca3af;">{{ $supportEmail }}</a>@endif.
            </p>
            <p style="margin:12px 0 0;font-size:11px;color:#9ca3af;">
                &copy; {{ date('Y') }} <a href="https://ckenterprises.co.uk/" style="color:#9ca3af;">CK Enterprises UK</a>. All rights reserved.
            </p>
        </div>
    </div>
</body>
</html>
