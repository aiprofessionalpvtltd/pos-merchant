<?php

return [
    'conversion_rate' => (float) env('CONVERSION_RATE', 10500),

    'alt_currency' => 'SLSH',

    'default_subscription_plan_id' => (int) env('SUBSCRIPTION_ID', 2),

    'pin' => [
        'max_attempts' => 5,
        'lockout_minutes' => 15,
        'weak_pins' => ['0000', '1234'],
    ],

    'otp' => [
        'length' => 6,
        'ttl_seconds' => 300,
        'resend_after_seconds' => 60,
        'max_attempts' => 5,
        'reset_token_ttl_seconds' => 600,
    ],

    'confirmation_ttl_seconds' => 300,

    'registration' => [
        'quote_ttl_seconds' => 900,
        'invoice_ttl_seconds' => 600,
        'poll_after_seconds' => 3,
        'fees' => [
            'registration' => ['base' => (int) env('REGISTRATION_FEE', 500), 'fee' => (int) env('REGISTRATION_FEE_CHARGE', 50)],
            'verification' => ['base' => (int) env('VERIFICATION_FEE', 500), 'fee' => (int) env('VERIFICATION_FEE_CHARGE', 50)],
        ],
    ],

    'providers' => [
        'timeout' => (int) env('API_TIMEOUT', 30),
        'edahab' => [
            'api_key' => env('EXELO_API_KEY'),
            'agent_code' => env('EXELO_AGENT_CODE'),
            'secret' => env('SECRET_KEY'),
            'base_url' => 'https://edahab.net/api/api',
        ],
        'waafi' => [
            'merchant_uid' => env('WAAFI_MERCHANT_UID'),
            'api_user_id' => env('WAAFI_API_USER_ID'),
            'api_key' => env('WAAFI_API_KEY'),
            'url' => 'https://api.waafipay.net/asm',
        ],
    ],

    // Local testing only: enables POST /registration/invoices/{id}/simulate-payment
    'simulate_payments' => (bool) env('EXELO_SIMULATE_PAYMENTS', false),

    // Local development only: returns the PIN-reset OTP in POST /auth/pin/reset/request
    'expose_otp' => (bool) env('EXELO_EXPOSE_OTP', false),

    'country' => 'SO',

    'states' => [
        ['code' => 'awdal', 'name' => 'Awdal'],
        ['code' => 'maroodi_jeex', 'name' => 'Maroodi Jeex'],
        ['code' => 'togdheer', 'name' => 'Togdheer'],
        ['code' => 'sahil', 'name' => 'Sahil'],
        ['code' => 'sool', 'name' => 'Sool'],
    ],
];
