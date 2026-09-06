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

    'hmac_secret' => env('QR_HMAC_SECRET', env('APP_KEY')),

];
