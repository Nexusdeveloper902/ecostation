<?php

return [
    // Defaults; admin overrides live in the settings table (SettingsService).
    'points' => ['plastic' => 10, 'metal' => 15, 'glass' => 8, 'paper' => 5, 'other' => 0],
    'photos' => ['retention_days' => 30],
    'pairing_window_seconds' => (int) env('PAIRING_WINDOW_SECONDS', 45),
    'student_email_domain' => 'ecostation.test',
    // Sin valor: cada estudiante recibe una contraseña aleatoria propia.
    // Solo pruebas/despliegues controlados fijan una clave compartida.
    'student_initial_password' => env('STUDENT_INITIAL_PASSWORD'),
    // La semilla de demostración solo corre en producción con permiso explícito.
    'demo_seed' => env('ECOSTATION_DEMO') === '1',
    'capture' => [
        'ttl_seconds' => (int) env('RECYCLING_CAPTURE_TTL', 300),
        'classify_window_seconds' => (int) env('RECYCLING_CLASSIFY_WINDOW', 600),
    ],
    'leaderboard' => ['top' => 10],
    'device_auth' => [
        'allow_legacy_bearer' => env('DEVICE_AUTH_ALLOW_LEGACY_BEARER', true),
        'rate_limit_per_minute' => (int) env('DEVICE_AUTH_RATE_LIMIT', 60),
    ],
    'classifier' => [
        'driver' => env('RECYCLING_CLASSIFIER_DRIVER', 'stub'),
        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'model' => 'deepseek-flash',
            'timeout' => 15,
        ],
    ],
];
