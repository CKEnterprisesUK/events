<?php

return [

    /*
    |--------------------------------------------------------------------------
    | QR token HMAC secret
    |--------------------------------------------------------------------------
    |
    | Every Order's QR_Token is an HMAC of its Order_Reference computed with
    | this Platform-wide secret. The secret MUST be stable across deploys so
    | previously issued QR tokens continue to verify at scan time (design →
    | Property 23; Requirements 14.2, 16.4). It lives in `.env` outside the web
    | root; when unset it falls back to the application key so local/testing
    | environments have a deterministic secret without extra configuration.
    |
    */

    'hmac_secret' => env('QR_HMAC_SECRET') ?: env('APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Accept QR codes whose token no longer verifies
    |--------------------------------------------------------------------------
    |
    | When true, a scanned `reference.token` whose HMAC does not verify (e.g.
    | tickets emailed before the secret changed) falls back to looking the
    | Order up by its reference — exactly like a manually typed reference —
    | and still runs every tenant / event / confirmed / already-scanned check.
    | Only a reference that matches no Order stays "invalid". Each fallback
    | check-in is logged as a warning. Set to false to restore strict HMAC
    | verification once all live tickets carry current-secret tokens.
    |
    */

    'accept_unverified_tokens' => (bool) env('QR_ACCEPT_UNVERIFIED_TOKENS', true),

];
