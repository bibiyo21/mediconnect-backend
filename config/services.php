<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // SMS provider (D-07 still open). "mail-preview" is LOCAL ONLY: messages appear in Mailpit.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'disabled'),
    ],

    // Mobile push (plan Q11): "disabled" (the default) sends nothing; "log" records a line only and is refused in production (set it in .env for LOCAL). Real FCM and APNs delivery needs owner-provided credentials.
    'push' => [
        'driver' => env('PUSH_DRIVER', 'disabled'),
        // A push claimed (SENDING) longer ago than this is taken over by the next worker: its worker died before finishing.
        'reclaim_after_minutes' => (int) env('PUSH_RECLAIM_AFTER_MINUTES', 10),
    ],

];
