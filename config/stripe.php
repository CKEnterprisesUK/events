<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe API keys
    |--------------------------------------------------------------------------
    |
    | The Platform's secret key drives Connect onboarding (Account Links),
    | Checkout Session creation as direct charges on connected accounts, and
    | refunds. The webhook secret verifies incoming webhook signatures (used by
    | the webhook task). These are never referenced in automated tests: the
    | Stripe boundary is always the fake there. (design → Stripe boundary)
    |
    */

    'secret' => env('STRIPE_SECRET'),

    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

];
