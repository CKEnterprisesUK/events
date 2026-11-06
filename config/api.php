<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API rate-limit windows (per minute)
    |--------------------------------------------------------------------------
    |
    | Per-minute ceilings for the sensitive API endpoints, consumed by the
    | named `RateLimiter::for(...)` declarations in AppServiceProvider::boot
    | and attached to routes as `throttle:{name}` middleware. Keeping the
    | limits here (env-backed) lets door throughput and anti-guessing windows
    | be tuned per deployment without a code change. (Requirements 15.1–15.3)
    |
    |   - login : sign-in credential submission, keyed per email+IP so
    |             brute-force against one account is capped without one IP
    |             locking out every account. (Requirement 15.3)
    |   - 2fa   : TOTP submission, keyed per email+IP to blunt code guessing
    |             against the two-factor challenge. (Requirement 15.1)
    |   - scan  : check-in submission, keyed per authenticated operator so one
    |             operator's abuse never throttles another's door. (15.2)
    |
    */

    'throttle' => [
        'login' => (int) env('API_THROTTLE_LOGIN', 5),
        '2fa' => (int) env('API_THROTTLE_2FA', 5),
        'scan' => (int) env('API_THROTTLE_SCAN', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Live dashboard snapshot cache (seconds)
    |--------------------------------------------------------------------------
    |
    | How long the assembled live-door snapshot (headline counts, per-operator
    | split and recent-scan feed) is shared between viewers. Every open door
    | display and scanner dashboard polls every couple of seconds, so a short
    | cache turns N viewers into one database build per window, at the cost of
    | figures being at most this many seconds old. Keep it below the poll
    | interval (2.5s). Set to 0 to disable the cache.
    |
    */

    'snapshot_ttl' => (int) env('API_SNAPSHOT_TTL', 2),

];
