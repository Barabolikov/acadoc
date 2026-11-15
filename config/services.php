<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    // Капча Cloudflare Turnstile (форми входу, реєстрації та завантаження роботи).
    // Ключі: https://dash.cloudflare.com → Turnstile. Без ключів форми не приймаються.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // Статус «Преміум» за символічну пожертву
    'premium' => [
        'price_uah' => (int) env('PREMIUM_PRICE_UAH', 10),                     // на місяць
        'lifetime_price_uah' => (int) env('PREMIUM_LIFETIME_PRICE_UAH', 100),  // назавжди
    ],

    // monobank: банка для пожертв + особистий API (https://api.monobank.ua/)
    'monobank' => [
        'token' => env('MONOBANK_TOKEN'),
        'jar_id' => env('MONOBANK_JAR_ID'),         // id банки з client-info (php artisan monobank:setup)
        'jar_url' => env('MONOBANK_JAR_URL'),       // https://send.monobank.ua/jar/...
        'webhook_secret' => env('MONOBANK_WEBHOOK_SECRET'),
    ],

    // PayPal Checkout (Orders API v2). Гривню PayPal не підтримує — сума в USD/EUR.
    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox'),    // sandbox | live
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        'amount' => env('PAYPAL_AMOUNT', '1.00'),                    // на місяць
        'lifetime_amount' => env('PAYPAL_LIFETIME_AMOUNT', '5.00'),  // назавжди
        'currency' => env('PAYPAL_CURRENCY', 'USD'),
    ],

];
