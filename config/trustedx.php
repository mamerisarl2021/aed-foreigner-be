<?php

declare(strict_types=1);

return [

    'base_url' => env('TX_BASE_URL'),

    'client_id' => env('TX_CLIENT_ID'),

    'client_secret' => env('TX_CLIENT_SECRET'),

    'admins_logged_as' => env('TX_ADMINS_LOGGED_AS'),

    'clients_logged_as' => env('TX_CLIENTS_LOGGED_AS'),

    'redirect_url' => env('TX_REDIRECT_URL'),

    'anip_base_url' => env('ANIP_BASE_URL'),

    'anip_username' => env('ANIP_USERNAME'),

    'anip_password' => env('ANIP_PASSWORD'),

    'timestamp' => [
        'url' => env('TIMESTAMP_API_URL'),
        'username' => env('TIMESTAMP_API_USERNAME'),
        'password' => env('TIMESTAMP_API_PASSWORD'),
    ],

];
