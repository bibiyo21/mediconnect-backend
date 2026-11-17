<?php

/*
 * CORS for first-party browser apps (D-04). Cookie sessions need credentials,
 * so origins are an explicit allowlist; never "*".
 *
 * Local defaults: mediconnect-web (3000), mediconnect-admin (3001) and the
 * Expo web build (8081). Deployments set CORS_ALLOWED_ORIGINS.
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,http://localhost:3001,http://localhost:8081'
    ))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'Authorization', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Request-Id', 'Idempotency-Key', 'Accept-Language', 'X-Client'],

    // Readable by browser code: correlation id, replay marker, rate-limit wait.
    'exposed_headers' => ['X-Request-Id', 'Idempotency-Replayed', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => true,
];
