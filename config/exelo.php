<?php

return [
    'conversion_rate' => (float) env('CONVERSION_RATE', 10500),

    'alt_currency' => 'SLSH',

    // A typo of one extra zero would misprice every till; changes outside this range are refused.
    'exchange_rate_range' => [
        'min' => (int) env('EXCHANGE_RATE_MIN', 5000),
        'max' => (int) env('EXCHANGE_RATE_MAX', 15000),
    ],

    'files' => [
        'disk' => env('EXELO_FILES_DISK', 'public'),
        // Unattached uploads older than this are swept by prune:files.
        'orphan_ttl_hours' => 24,
        'purposes' => [
            'product_image' => ['max_bytes' => 5 * 1024 * 1024, 'mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'max_dimension' => 1200, 'thumb_dimension' => 200, 'encode' => 'webp'],
            'signature' => ['max_bytes' => 512 * 1024, 'mimes' => ['image/png'], 'max_dimension' => null, 'thumb_dimension' => null, 'encode' => 'png'],
            'merchant_logo' => ['max_bytes' => 2 * 1024 * 1024, 'mimes' => ['image/jpeg', 'image/png'], 'max_dimension' => 512, 'thumb_dimension' => null, 'encode' => null],
        ],
    ],

    'payments' => [
        // One combined fee on wallet payments. Gold shops pass it to the customer, other plans absorb it.
        'wallet_fee_rate' => (float) env('EXELO_WALLET_FEE_RATE', 0.0285),
        'quote_ttl_seconds' => 900,
        'card' => ['enabled' => (bool) env('EXELO_CARD_ENABLED', false), 'environment' => env('BRAINTREE_ENVIRONMENT', 'sandbox')],
        'nfc' => ['enabled' => (bool) env('EXELO_NFC_ENABLED', false)],
    ],

    'preference_defaults' => [
        'receipt' => ['footer' => null, 'show_logo' => true, 'print_automatically' => false],
        'register' => ['allow_price_override' => true, 'require_customer_on_hold' => true, 'scan_sound' => true],
        'alerts' => ['default_alarm_limit' => 4, 'default_stock_limit' => 10],
    ],

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

    'subscription' => [
        // Days after expiry a plan stays usable, because a shop can be offline for days.
        'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 5),
        // A paid plan can be renewed once it is this close to expiring.
        'renew_window_days' => 7,
        // How long a cash payment waits for staff confirmation.
        'cash_ttl_hours' => 72,
    ],

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
            // IssueInvoice answers only once the customer has approved or declined the prompt.
            'timeout' => (int) env('EDAHAB_TIMEOUT', 90),
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
