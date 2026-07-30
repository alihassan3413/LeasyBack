<?php

return [
    'paths' => [
        'api/*',
        'auth/*',
        'userprofile/*',
        'b2b/*',
        'profile',
        'profile/*',
        'workshop/*',
        'vehicle/*',
        'order/*',
        'image/*',
        'admin/*',
        'tim/*',
        'dekra/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'https://app.leasyback.com'),
    ],

    'allowed_origins_patterns' => [
        '#^http://(localhost|127\.0\.0\.1)(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,
];
