<?php

return [
    'driver' => env('NOTIFICATION_DRIVER', 'kafka'),

    'platform' => env('NOTIFICATION_PLATFORM', 'PORTAL'),

    'topics' => [
        'email' => env('KAFKA_TOPIC_EMAIL', 'notify.email'),
        'sms' => env('KAFKA_TOPIC_SMS', 'notify.sms'),
        'websocket' => env('KAFKA_TOPIC_WEBSOCKET', 'notify.websocket'),
    ],
];
