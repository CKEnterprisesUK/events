{{--
    A platform operational announcement from a Super_Admin to selected
    Company_Users. $bodyHtml is authored as Markdown and rendered to SAFE HTML
    by the sender (html_input => 'escape'), so it is trusted here and emitted
    with {!! !!}. The subject/name are escaped as normal.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
        <div style="background:#ffffff;border-radius:8px;overflow:hidden;border-top:6px solid #111827;">
            <div style="padding:24px 24px 8px;">
                <h1 style="margin:0;font-size:20px;color:#111827;">{{ $subjectLine }}</h1>
            </div>

            <div style="padding:8px 24px 24px;color:#374151;font-size:15px;line-height:1.55;">
                <p style="margin:0 0 16px;">Hi {{ $recipientName }},</p>

                <div class="announcement-body">
                    {!! $bodyHtml !!}
                </div>
            </div>
        </div>

        <p style="text-align:center;color:#9ca3af;font-size:12px;margin:16px 0 0;">
            Sent by {{ $appName }} platform administration.
        </p>
    </div>
</body>
</html>
