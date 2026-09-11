{{--
    Invitation email: an Owner has invited this address to join their Company
    with an assigned role. Carries the public accept link keyed on the
    invitation's opaque token. (Requirement 4.1)
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You're invited to join {{ $companyName }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
        <div style="background:#ffffff;border-radius:8px;overflow:hidden;border-top:6px solid #111827;">
            <div style="padding:24px;text-align:center;">
                <h1 style="margin:0;font-size:20px;color:#111827;">You've been invited</h1>
                <p style="margin:8px 0 0;color:#6b7280;">{{ $companyName }} has invited you to join their team on {{ $appName }}.</p>
            </div>

            <div style="padding:0 24px 24px;">
                <p style="margin:0 0 16px;color:#374151;">
                    You've been invited to join <strong>{{ $companyName }}</strong> as
                    <strong>{{ $roleLabel }}</strong>. Click the button below to set your
                    password and get started.
                </p>

                <div style="text-align:center;margin:24px 0;">
                    <a href="{{ $acceptUrl }}"
                       style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-size:15px;">
                        Accept invitation
                    </a>
                </div>

                <p style="margin:0 0 8px;color:#6b7280;font-size:13px;">
                    Or paste this link into your browser:
                </p>
                <p style="margin:0 0 16px;word-break:break-all;font-size:13px;">
                    <a href="{{ $acceptUrl }}" style="color:#2563eb;">{{ $acceptUrl }}</a>
                </p>

                @if ($expiresAt)
                    <p style="margin:0;color:#9ca3af;font-size:12px;">
                        This invitation expires on {{ $expiresAt->toDayDateTimeString() }}.
                    </p>
                @endif
            </div>
        </div>

        <p style="text-align:center;color:#9ca3af;font-size:12px;margin:16px 0 0;">
            This invitation was sent to {{ $email }}. If you weren't expecting it, you can ignore this email.
        </p>
    </div>
</body>
</html>
