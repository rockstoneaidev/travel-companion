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

        // Places/Routes (E16). EDGE-ONLY: what this key fetches is used in the
        // response and discarded — only the place_id string may ever be stored
        // (conventions/09). Absent = the verifier stays quiet and hours are simply
        // unknown, which is a supported state, not a broken one.
        'maps_key' => env('GOOGLE_MAPS_API_KEY', ''),
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

    /*
    | Gemini behind the LlmClient port (PRD Appendix A). Two tiers: a card summary
    | is not worth what a pack draft is worth. Model ids verified against the live
    | models endpoint — a wrong id is a 404 on every generation.
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'timeout' => env('GEMINI_TIMEOUT', 30),
        'models' => [
            'cheap' => env('GEMINI_MODEL_CHEAP', 'gemini-3.1-flash-lite'),
            'capable' => env('GEMINI_MODEL_CAPABLE', 'gemini-3.5-flash'),
        ],
    ],

    /*
    | Firebase Cloud Messaging (E31; PRD Appendix A).
    |
    | UNSET BY DEFAULT, and the default is the safe one: with no project id the sender
    | reaches nobody. A misconfigured environment that silently pushes to real phones is a
    | far worse failure than one that silently pushes to none.
    |
    | FCM is a PROCESSOR — it receives a push token and a message body — so it needs a DPA
    | before the first real send (ROPA §6, PROCESSORS.md, and E32 owns it).
    */
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'access_token' => env('FCM_ACCESS_TOKEN'),
    ],

];
