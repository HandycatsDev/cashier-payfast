<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PayFast Credentials
    |--------------------------------------------------------------------------
    |
    | Your PayFast merchant credentials. The merchant ID and key are used
    | to authenticate with PayFast. The passphrase is used for signature
    | generation and ITN validation.
    |
    */

    'merchant_id' => env('CASHIER_MERCHANT_ID'),

    'merchant_key' => env('CASHIER_MERCHANT_KEY'),

    'passphrase' => env('CASHIER_PASSPHRASE'),

    /*
    |--------------------------------------------------------------------------
    | Cashier Path
    |--------------------------------------------------------------------------
    |
    | This is the base URI path where Cashier's views, such as the webhook
    | route, will be available. You're free to tweak this path based on
    | the needs of your particular application or design preferences.
    |
    */

    'path' => env('CASHIER_PATH', 'cashier'),

    /*
    |--------------------------------------------------------------------------
    | Cashier URLs
    |--------------------------------------------------------------------------
    |
    | These URLs control where users are redirected after payment and where
    | PayFast sends ITN (Instant Transaction Notification) callbacks.
    |
    */

    'return_url' => env('CASHIER_RETURN_URL', '/cashier/return'),

    'cancel_url' => env('CASHIER_CANCEL_URL', '/cashier/cancel'),

    'notify_url' => env('CASHIER_NOTIFY_URL', '/cashier/webhook'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | This is the default currency that will be used when generating charges
    | from your application. PayFast primarily supports ZAR.
    |
    */

    'currency' => env('CASHIER_CURRENCY', 'ZAR'),

    /*
    |--------------------------------------------------------------------------
    | Currency Locale
    |--------------------------------------------------------------------------
    |
    | This is the default locale in which your money values are formatted in
    | for display. To utilize other locales besides the default en locale
    | verify you have the "intl" PHP extension installed on the system.
    |
    */

    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en_ZA'),

    /*
    |--------------------------------------------------------------------------
    | PayFast Sandbox
    |--------------------------------------------------------------------------
    |
    | This option allows you to toggle between the PayFast live environment
    | and its sandbox environment for testing.
    |
    */

    'sandbox' => env('CASHIER_SANDBOX', false),

];
