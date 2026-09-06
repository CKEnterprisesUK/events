{{--
    Diagnostic test email sent by a Super_Admin from the platform Settings page
    to troubleshoot the configured mail transport. Carries no tenant/Order
    context — it exists only to confirm end-to-end delivery.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test email from {{ $appName }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
        <div style="background:#ffffff;border-radius:8px;overflow:hidden;border-top:6px solid #111827;">
            <div style="padding:24px;text-align:center;">
                <h1 style="margin:0;font-size:20px;color:#111827;">Mail is working</h1>
                <p style="margin:8px 0 0;color:#6b7280;">This is a test message from {{ $appName }}.</p>
            </div>

            <div style="padding:0 24px 24px;">
                <p style="margin:0 0 16px;color:#374151;">
                    If you are reading this, the configured mail transport delivered a
                    message successfully. You can safely ignore this email.
                </p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                    <tbody>
                        <tr>
                            <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Delivered to</th>
                            <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;">{{ $recipient }}</td>
                        </tr>
                        <tr>
                            <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Mailer</th>
                            <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;">{{ $mailerName }}</td>
                        </tr>
                        <tr>
                            <th align="left" style="padding:8px;font-size:13px;color:#6b7280;">Sent at</th>
                            <td align="right" style="padding:8px;font-size:13px;">{{ $sentAt }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p style="text-align:center;color:#9ca3af;font-size:12px;margin:16px 0 0;">
            Sent by {{ $appName }} platform administration.
        </p>
    </div>
</body>
</html>
