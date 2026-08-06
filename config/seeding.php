<?php

return [
    'admin' => [
        'name' => env('SEED_ADMIN_NAME', 'Admin'),
        'email' => env('SEED_ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('SEED_ADMIN_PASSWORD', 'Secret123!'),
    ],
    'agent' => [
        'name' => env('SEED_AGENT_NAME', 'Agent'),
        'email' => env('SEED_AGENT_EMAIL', 'agent@example.com'),
        'password' => env('SEED_AGENT_PASSWORD', 'Secret123!'),
    ],
    'responsable' => [
        'name' => env('SEED_RESPONSABLE_NAME', 'Responsable'),
        'email' => env('SEED_RESPONSABLE_EMAIL', 'responsable@example.com'),
        'password' => env('SEED_RESPONSABLE_PASSWORD', 'Secret123!'),
    ],
    'manager' => [
        'name' => env('SEED_MANAGER_NAME', 'Manager'),
        'email' => env('SEED_MANAGER_EMAIL', 'manager@example.com'),
        'password' => env('SEED_MANAGER_PASSWORD', 'Secret123!'),
    ],
];
