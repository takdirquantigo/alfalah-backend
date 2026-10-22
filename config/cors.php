<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'https://mohammadenterprise.com',
        'http://localhost:8001',
        'http://localhost:8002',
        'http://localhost:8003',
        'http://localhost:8004',
        'http://localhost:8005',
        'http://localhost:8006',
    ], 

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];