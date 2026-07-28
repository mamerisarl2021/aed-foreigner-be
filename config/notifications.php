<?php

return [
    'driver' => env('NOTIFICATION_DRIVER', 'kafka'),

    'platform' => env('NOTIFICATION_PLATFORM', 'PORTAL'),

    /*
     * Mask OTPs, passwords and tokens in log-driver output. Keep enabled everywhere
     * except local dev, where you may need the plaintext OTP to walk through flows.
     */
    'redact_log_secrets' => (bool) env('NOTIFICATION_REDACT_SECRETS', true),

    'topics' => [
        'email' => env('KAFKA_TOPIC_EMAIL', 'notify.email'),
        'sms' => env('KAFKA_TOPIC_SMS', 'notify.sms'),
        'websocket' => env('KAFKA_TOPIC_WEBSOCKET', 'notify.websocket'),
    ],
];
