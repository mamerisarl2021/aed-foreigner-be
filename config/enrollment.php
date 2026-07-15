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
            'level1' => ['tech_one', 'tech_two', 'tech_three'],
            'level2' => ['superviseur'],
            'level3' => ['manager'],
        ],
    ],

    'similarity' => [
        'max_results' => 5,
    ],
];
