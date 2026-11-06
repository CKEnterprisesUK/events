{{-- Alert to Super_Admins that 403/404/500 responses are above their usual baseline. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error spike detected</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
        <div style="background:#ffffff;border-radius:8px;overflow:hidden;">
            @include('emails.partials.platform-logo')
            <div style="height:4px;background:#b91c1c;line-height:4px;font-size:0;">&nbsp;</div>
            <div style="padding:24px;">
                <h1 style="margin:0;font-size:20px;">Error spike detected</h1>
                <p style="margin:8px 0 0;color:#6b7280;">
                    The current hour is running well above the usual level for these responses.
                </p>
            </div>
            <div style="padding:0 24px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th align="left" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Status</th>
                            <th align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">This hour</th>
                            <th align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Usual per hour</th>
                            <th align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280;">Alert level</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($spikes as $s)
                            <tr>
                                <td style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:14px;font-weight:bold;">{{ $s['status'] }}</td>
                                <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:14px;color:#b91c1c;font-weight:bold;">{{ number_format($s['current']) }}</td>
                                <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:14px;">{{ $s['mean'] }}</td>
                                <td align="right" style="padding:8px;border-bottom:1px solid #e5e7eb;font-size:14px;">{{ $s['threshold'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="padding:0 24px 24px;">
                <a href="{{ $errorsUrl }}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:6px;font-size:14px;">View error reports</a>
            </div>
        </div>
        <p style="text-align:center;color:#9ca3af;font-size:12px;margin:16px 0 0;">
            You get this because you are a Super_Admin. Repeat alerts for the same status are held back for a few hours.
        </p>
    </div>
</body>
</html>
