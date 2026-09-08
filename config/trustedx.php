<?php

declare(strict_types=1);

return [

    'base_url' => env('TX_BASE_URL'),

    'client_id' => env('TX_CLIENT_ID'),

    'client_secret' => env('TX_CLIENT_SECRET'),

    'admins_logged_as' => env('TX_ADMINS_LOGGED_AS'),

    'clients_logged_as' => env('TX_CLIENTS_LOGGED_AS'),

    'redirect_url' => env('TX_REDIRECT_URL'),

    'redirect_path' => env('TX_REDIRECT_PATH'),

    'scope' => env('TX_SCOPE', 'urn:gob:basic:profile'),

    /*
    | Redirect URIs allowed to exchange an authorization code. The client sends
    | the one it used at /authorize; TrustedX rejects the exchange unless both
    | match literally, so the value must come from the client, not from here.
    | This list only decides which clients we accept it from.
    */
    'allowed_redirect_urls' => array_values(array_unique(array_filter(array_map(
        'trim',
        array_merge(
            [(string) env('TX_REDIRECT_URL')],
            explode(',', (string) env('TX_ALLOWED_REDIRECT_URLS', ''))
        )
    )))),

    'anip_base_url' => env('ANIP_BASE_URL'),

    'anip_username' => env('ANIP_USERNAME'),

    'anip_password' => env('ANIP_PASSWORD'),

    'timestamp' => [
        'url' => env('TIMESTAMP_API_URL'),
        'username' => env('TIMESTAMP_API_USERNAME'),
        'password' => env('TIMESTAMP_API_PASSWORD'),
    ],

    /*
    | Temporary exception to guidelines §7.2: log every TrustedX HTTP call at
    | INFO (including password/PIN and access_token). Set true to enable.
    */
    'log_calls' => (bool) env('TRUSTEDX_LOG_CALLS', false),

    'verify_ssl' => (bool) env('TRUSTEDX_VERIFY_SSL', true),

    'timeout' => (int) env('TRUSTEDX_TIMEOUT', 15),

];
