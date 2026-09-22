<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => array_values(array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS'))))),
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:4200'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => array_values(array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_HEADERS', 'Accept,Authorization,Content-Type,Origin,X-Branch-Id,X-Requested-With,X-XSRF-TOKEN'))))),
    'exposed_headers' => ['X-Request-Id'],
    'max_age' => 0,
    'supports_credentials' => true,
];
