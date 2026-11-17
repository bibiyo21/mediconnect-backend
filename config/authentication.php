<?php

/*
 * Authentication tuning (D-04, api-specification.md §1.7, rbac-permissions.md §7).
 * Values are starting points; change them here, not in code.
 */
return [
    'access_token_minutes' => (int) env('AUTH_ACCESS_TOKEN_MINUTES', 15),
    'refresh_token_days' => (int) env('AUTH_REFRESH_TOKEN_DAYS', 30),

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 5,
        'verification_token_minutes' => 10,
        // Codes sent per destination within the window before refusing (429).
        'per_destination_limit' => 5,
        'per_destination_window_minutes' => 15,
    ],

    'mfa' => [
        'issuer' => env('APP_NAME', 'MediConnect'),
        'pending_login_minutes' => 5,
        'max_attempts' => 5,
        'step_up_minutes' => 5,
        'recovery_codes' => 10,
    ],

    'password' => [
        'min_length' => 10,
        // Checks the Have I Been Pwned range API (k-anonymity: only a 5-character
        // hash prefix leaves the server). Off in tests to avoid network calls.
        'check_breached' => (bool) env('AUTH_CHECK_BREACHED_PASSWORDS', env('APP_ENV') !== 'testing'),
    ],

    // Versions of the legal documents a user must accept at registration.
    // Placeholders until legal review publishes them (D-23).
    'legal' => [
        'privacy_notice_version' => env('LEGAL_PRIVACY_NOTICE_VERSION', 'draft-2026-11'),
        'terms_version' => env('LEGAL_TERMS_VERSION', 'draft-2026-11'),
    ],
];
