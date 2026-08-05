<?php

return [
    'admin' => [
        'name' => env('SEED_ADMIN_NAME', 'Admin'),
        'email' => env('SEED_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('SEED_ADMIN_PASSWORD', 'Secret123!'),
    ],

    'agent' => [
        'name' => env('SEED_AGENT_NAME'),
        'email' => env('SEED_AGENT_EMAIL'),
        'password' => env('SEED_AGENT_PASSWORD'),
    ],

    'responsable' => [
        'name' => env('SEED_RESPONSABLE_NAME'),
        'email' => env('SEED_RESPONSABLE_EMAIL'),
        'password' => env('SEED_RESPONSABLE_PASSWORD'),
    ],

    'manager' => [
        'name' => env('SEED_MANAGER_NAME'),
        'email' => env('SEED_MANAGER_EMAIL'),
        'password' => env('SEED_MANAGER_PASSWORD'),
    ],
];
