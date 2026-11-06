{{--
    Platform logo band for emails that come from the system / CK Enterprises
    (announcements, invitations, alerts, verification, diagnostics) and are NOT
    sent to a ticket-buying customer.

    The logo artwork is white text on a transparent background, so it must sit
    on the same dark navy as the main website header (.pub-header, #141a2e).
    An absolute URL is required because email clients cannot resolve relative
    paths; url() uses APP_URL when rendered from the queue.
--}}
<div style="background:#141a2e;padding:20px 24px;text-align:center;">
    <a href="{{ url('/') }}" style="text-decoration:none;">
        <img src="{{ url('/images/logo.png') }}" alt="Events by CK Enterprises UK" width="220" height="55"
             style="display:inline-block;width:220px;max-width:100%;height:auto;border:0;outline:none;">
    </a>
</div>
