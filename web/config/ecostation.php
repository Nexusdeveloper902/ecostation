<?php

return [
    // Defaults; admin overrides live in the settings table (SettingsService).
    'points' => ['plastic' => 10, 'metal' => 15, 'glass' => 8, 'paper' => 5, 'other' => 0],
    'photos' => ['retention_days' => 30],
    'pairing_window_seconds' => (int) env('PAIRING_WINDOW_SECONDS', 45),
    'student_email_domain' => 'ecostation.test',
    'student_initial_password' => 'password',
    'capture' => [
        'ttl_seconds' => (int) env('RECYCLING_CAPTURE_TTL', 300),
        'classify_window_seconds' => (int) env('RECYCLING_CLASSIFY_WINDOW', 600),
    ],
    'leaderboard' => ['top' => 10],
    'device_auth' => ['allow_legacy_bearer' => env('DEVICE_AUTH_ALLOW_LEGACY_BEARER', true)],
    'classifier' => [
        'driver' => env('RECYCLING_CLASSIFIER_DRIVER', 'stub'),
        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model' => 'deepseek-flash',
            'timeout' => 15,
        ],
    ],
];
