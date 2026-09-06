{{--
    Branded ticket email for a confirmed Order. Shows the effective
    Company/Event branding (logo, primary colour), the customisable ticket
    information fields, the Order breakdown, and the scannable QR payload
    (HMAC(secret, Order_Reference)) the attendee presents at entry.
    (Requirements 14.4, 14.6)
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
                @if ($branding->hasLogo())
                    <img src="{{ $branding->logoPath }}" alt="{{ $eventName }}" style="max-height:64px;margin-bottom:12px;">
                @endif
                <h1 style="margin:0;font-size:20px;color:{{ $primary }};">{{ $eventName }}</h1>
                <p style="margin:8px 0 0;color:#6b7280;">Your tickets are confirmed.</p>
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

                {{-- Customisable ticket information fields. (Requirements 7.4, 14.6) --}}
                @if ($branding->hasTicketFields())
                    <div style="margin-bottom:16px;">
                        @foreach ($branding->ticketFieldDefs as $field)
                            @if (is_array($field) && isset($field['label']))
                                <p style="margin:0 0 4px;font-size:13px;color:#374151;">
                                    <strong>{{ $field['label'] }}:</strong>
                                    {{ $field['value'] ?? '' }}
                                </p>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- The scannable QR payload: HMAC(secret, Order_Reference). The
                 scanner decodes this value and recomputes the HMAC to validate.
                 (Requirements 14.1, 14.2) --}}
            <div style="padding:16px 24px 24px;text-align:center;">
                <div style="display:inline-block;padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
                    <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">Order {{ $order->order_reference }}</div>
                    <div data-qr-payload="{{ $qrPayload }}" style="font-family:monospace;font-size:12px;word-break:break-all;color:#111827;">
                        {{ $qrPayload }}
                    </div>
                </div>
            </div>
        </div>

        <p style="text-align:center;color:#9ca3af;font-size:12px;margin-top:16px;">
            Order reference {{ $order->order_reference }}
        </p>
    </div>
</body>
</html>
