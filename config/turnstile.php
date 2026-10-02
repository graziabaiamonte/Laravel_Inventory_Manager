<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Turnstile Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable Turnstile verification globally.
    | When disabled, all validation will pass automatically.
    |
    */
    'enabled' => env('TURNSTILE_ENABLED', ! empty(env('TURNSTILE_SITEKEY')) && ! empty(env('TURNSTILE_SECRETKEY'))),

    /*
    |--------------------------------------------------------------------------
    | Turnstile Site Key
    |--------------------------------------------------------------------------
    |
    | Your Cloudflare Turnstile site key.
    | Get yours at: https://dash.cloudflare.com/?to=/:account/turnstile
    |
    */
    'sitekey' => env('TURNSTILE_SITEKEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Turnstile Secret Key
    |--------------------------------------------------------------------------
    |
    | Your Cloudflare Turnstile secret key.
    | Keep this secret and never expose it in your frontend code.
    |
    */
    'secretkey' => env('TURNSTILE_SECRETKEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Verification Endpoint
    |--------------------------------------------------------------------------
    |
    | The Cloudflare Turnstile verification endpoint.
    | You should not need to change this.
    |
    */
    'endpoint' => env('TURNSTILE_ENDPOINT', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),

    /*
    |--------------------------------------------------------------------------
    | Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds for the verification request.
    |
    */
    'timeout' => env('TURNSTILE_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    |
    | Custom error messages for validation failures.
    |
    */
    'error_messages' => [
        'verification_failed' => 'The security verification failed. Please try again.',
        'missing_token' => 'Security verification is required.',
        'timeout' => 'Security verification timed out. Please try again.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing Mode
    |--------------------------------------------------------------------------
    |
    | When true, Turnstile verification will be bypassed.
    | Useful for automated testing environments.
    |
    */
    'testing' => env('TURNSTILE_TESTING', env('APP_ENV') === 'testing'),

    /*
    |--------------------------------------------------------------------------
    | Skip IPs
    |--------------------------------------------------------------------------
    |
    | IP addresses that should skip Turnstile verification.
    | Useful for local development or trusted networks.
    |
    */
    'skip_ips' => env('TURNSTILE_SKIP_IPS') ? explode(',', env('TURNSTILE_SKIP_IPS')) : [],

    /*
    |--------------------------------------------------------------------------
    | Widget Theme
    |--------------------------------------------------------------------------
    |
    | Default theme for the Turnstile widget: 'light', 'dark', or 'auto'
    |
    */
    'theme' => env('TURNSTILE_THEME', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Widget Size
    |--------------------------------------------------------------------------
    |
    | Default size for the Turnstile widget: 'normal', 'compact', or 'flexible'
    |
    */
    'size' => env('TURNSTILE_SIZE', 'normal'),
];
