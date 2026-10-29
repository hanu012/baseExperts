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

    /*
     * Razorpay Payment Links. Used only while the admin setting
     * `razorpay_payment_links_enabled` is on. Test-mode keys start
     * rzp_test_; the webhook secret is the one set on the webhook in the
     * Razorpay dashboard (not the key secret).
     */
    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        // How long a link stays payable. Razorpay requires 15+ minutes.
        'link_expiry_minutes' => (int) env('RAZORPAY_LINK_EXPIRY_MINUTES', 1440),
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
     * FCM HTTP v1 (BUILD_PLAN 7.2). private_key_base64 is the service
     * account's PEM private key, base64-encoded — a raw multi-line PEM
     * doesn't survive a single .env line cleanly. See the Before
     * Launch Checklist: no real Firebase project exists in this dev
     * environment, so these are blank until one is created.
     */
    /*
     * Firebase Authentication — password emails (reset and "set your
     * password") and, with Settings → Sign-in = Firebase, checking app
     * passwords (App\Services\AuthProvider). The Web API key is the
     * public one from Firebase Console → Project settings → General; it
     * can also be set on the Settings page, which wins. Server calls use
     * the FCM service account below (same Firebase project).
     */
    'firebase' => [
        'web_api_key' => env('FIREBASE_WEB_API_KEY'),
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'client_email' => env('FCM_CLIENT_EMAIL'),
        'private_key_base64' => env('FCM_PRIVATE_KEY_BASE64'),
    ],

];
