<?php

return [
    'driver' => env('ENROLLMENT_EVENTS_DRIVER', env('NOTIFICATION_DRIVER', 'log')),

    'topics' => [
        'otp_send' => env('KAFKA_TOPIC_OTP_SEND', 'enrolement.otp.send'),
        'created' => env('KAFKA_TOPIC_ENROLEMENT_CREATED', 'enrolement.created'),
        'status_changed' => env('KAFKA_TOPIC_ENROLEMENT_STATUS_CHANGED', 'enrolement.status_changed'),
        'approved' => env('KAFKA_TOPIC_ENROLEMENT_APPROVED', 'enrolement.approved'),
        'rejected' => env('KAFKA_TOPIC_ENROLEMENT_REJECTED', 'enrolement.rejected'),
        'completed' => env('KAFKA_TOPIC_ENROLEMENT_COMPLETED', 'enrolement.completed'),
    ],
];
