{{--
    Alert to the CK Enterprises support inbox that a Company_User raised a new
    support ticket from the in-dashboard "Contact support" form. The reply-to is
    set to the raiser (when known) so a reply from the inbox reaches them.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New support request</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
        <div style="background:#ffffff;border-radius:8px;overflow:hidden;border-top:6px solid #111827;">
            <div style="padding:24px;">
                <h1 style="margin:0;font-size:20px;color:#111827;">New support request</h1>
                <p style="margin:8px 0 0;color:#6b7280;">
                    {{ $companyName }} raised a ticket via Contact support.
                </p>
            </div>

            <div style="padding:0 24px 8px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                    <tbody>
                        <tr>
                            <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Company</th>
                            <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;">{{ $companyName }}</td>
                        </tr>
                        <tr>
                            <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Raised by</th>
                            <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;">
                                {{ $raiserName ?? 'Unknown' }}@if ($raiserEmail) &lt;{{ $raiserEmail }}&gt;@endif
                            </td>
                        </tr>
                        <tr>
                            <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Category</th>
                            <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;">{{ $categoryLabel }}</td>
                        </tr>
                        <tr>
                            <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Subject</th>
                            <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;">{{ $ticket->subject }}</td>
                        </tr>
                        <tr>
                            <th align="left" style="padding:8px;font-size:13px;color:#6b7280;">Account access</th>
                            <td align="right" style="padding:8px;font-size:13px;">
                                @if ($accessConsent)
                                    <span style="color:#065f46;font-weight:bold;">Granted</span>
                                @else
                                    <span style="color:#6b7280;">Not granted</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="padding:8px 24px 24px;">
                <p style="margin:0 0 6px;font-size:13px;color:#6b7280;">Message</p>
                <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:14px;font-size:14px;color:#374151;line-height:1.55;white-space:pre-wrap;">{{ $ticket->message }}</div>
            </div>
        </div>

        <p style="text-align:center;color:#9ca3af;font-size:12px;margin:16px 0 0;">
            Reply to this email to respond directly to the sender.
        </p>
    </div>
</body>
</html>
