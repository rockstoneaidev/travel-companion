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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Transactional mail on staging/production (MAIL_MAILER=resend). Local dev
    // sends to Mailpit over SMTP and never touches this. The var is
    // RESEND_API_KEY rather than Laravel's stock RESEND_KEY — that is the name
    // Resend's own dashboard gives it, and both .env files already carry it.
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // Sign in with Google (E22). Redirect URI must match the one registered in
    // the Google Cloud console exactly, including scheme and port.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | France corridor open data (DATA-SOURCES §7). Both are free keys on request.
    | Absent key ⇒ the adapter reports itself unsupported and the region degrades,
    | rather than the ingest failing (conventions/09 coverage honesty).
    */
    'datatourisme' => [
        'key' => env('DATATOURISME_API_KEY'),
    ],

    'openagenda' => [
        'key' => env('OPENAGENDA_API_KEY'),
    ],

];
