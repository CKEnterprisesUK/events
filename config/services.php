<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Microsoft Graph outbound mail (the `graph` mailer). Delivers via the
    | Graph `sendMail` endpoint using the OAuth2 client-credentials flow, so no
    | signed-in user is required — the app authenticates as itself against an
    | Azure AD app registration that has been granted the `Mail.Send`
    | application permission (with admin consent) and sends from the configured
    | organisation mailbox (`from`). Leave these blank to keep Graph dormant:
    | until the tenant/client id, secret and from mailbox are all present the
    | app falls back to SMTP even when the `graph` transport is selected.
    */
    'graph' => [
        'tenant_id' => env('GRAPH_MAIL_TENANT_ID'),
        'client_id' => env('GRAPH_MAIL_CLIENT_ID'),
        'client_secret' => env('GRAPH_MAIL_CLIENT_SECRET'),
        // The sending mailbox (user principal name or object id), e.g.
        // "tickets@yourdomain.com". Graph sends "as" this mailbox.
        'from' => env('GRAPH_MAIL_FROM'),
        // Whether to also write the message to the sender's Sent Items.
        'save_to_sent_items' => (bool) env('GRAPH_MAIL_SAVE_TO_SENT_ITEMS', true),
        // Azure AD authority + Graph base; overridable for sovereign clouds.
        'authority' => env('GRAPH_MAIL_AUTHORITY', 'https://login.microsoftonline.com'),
        'base_uri' => env('GRAPH_MAIL_BASE_URI', 'https://graph.microsoft.com'),
        'timeout' => (int) env('GRAPH_MAIL_TIMEOUT', 15),
    ],

    'nominatim' => [
        'base_uri' => env('NOMINATIM_BASE_URI', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('NOMINATIM_USER_AGENT'), // REQUIRED: 'EventTicketing/1.0 (ops@example.com)'
        'cache_ttl' => (int) env('NOMINATIM_CACHE_TTL', 60 * 60 * 24 * 30), // 30 days
        'timeout' => (int) env('NOMINATIM_TIMEOUT', 5),
    ],

];
