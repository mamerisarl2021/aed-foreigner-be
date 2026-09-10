<?php

return [
    'sla' => [
        'max_hours' => (int) env('ENROLLMENT_SLA_MAX_HOURS', 72),
        'alert_levels' => [
            // percent of max_hours elapsed => level key
            75 => 'level1',
            100 => 'level2',
            150 => 'level3',
        ],
        'notify_roles' => [
            'level1' => ['agent'],
            'level2' => ['responsable_de_validation'],
            'level3' => ['manager'],
        ],
    ],

    'similarity' => [
        'max_results' => 5,
        'cache_ttl_seconds' => (int) env('ENROLLMENT_SIMILARITY_CACHE_TTL_SECONDS', 120),
    ],

    'morale' => [
        'email_verification_hours' => (int) env('ENROLLMENT_MORALE_EMAIL_VERIFICATION_HOURS', 24),
        'phone_otp_ttl_minutes' => (int) env('ENROLLMENT_MORALE_PHONE_OTP_TTL_MINUTES', 5),
        'correction_days' => (int) env('ENROLLMENT_MORALE_CORRECTION_DAYS', 7),
        'correction_reminder_hours' => (int) env('ENROLLMENT_MORALE_CORRECTION_REMINDER_HOURS', 24),
    ],
];
