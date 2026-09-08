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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
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

    'regula' => [
        'mock' => (bool) env('REGULA_MOCK', false),
        'document_url' => env('REGULA_DOCUMENT_URL') ?: env('REGULA_URL'),
        'face_url' => env('REGULA_FACE_URL') ?: env('REGULA_URL'),
        'api_key' => env('REGULA_API_KEY'),
        'timeout' => (int) env('REGULA_TIMEOUT', 60),
        'document_scenario' => env('REGULA_DOCUMENT_SCENARIO', 'FullProcess'),
        // Face /api/match similarity is 0.0–1.0; below this threshold KYC fails closed.
        'match_threshold' => (float) env('REGULA_MATCH_THRESHOLD', 0.75),
    ],

];
