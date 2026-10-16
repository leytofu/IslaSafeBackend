<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'], 'allowed_methods' => ['*'],
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:5174,http://127.0.0.1:5173,http://127.0.0.1:5174,http://localhost:8082,http://127.0.0.1:8082')),
    'allowed_origins_patterns' => [], 'allowed_headers' => ['*'], 'exposed_headers' => [], 'max_age' => 600, 'supports_credentials' => true,
];
