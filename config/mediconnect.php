<?php

/*
 * Market defaults (CLAUDE.md §1.2). Change here, not in code.
 */
return [
    // Local dates (birth dates, "today") are judged in the market's timezone.
    'timezone' => env('MEDICONNECT_TIMEZONE', 'Asia/Manila'),

    // D-37: account holders must be adults; minors are dependents of a guardian.
    'minimum_account_age' => (int) env('MEDICONNECT_MINIMUM_ACCOUNT_AGE', 18),

    // Dependents (CLAUDE.md §4.3). The attestation text a guardian accepts is a
    // draft until legal review (D-23); clients must send the version they showed.
    'dependents' => [
        'max_per_guardian' => (int) env('MEDICONNECT_MAX_DEPENDENTS', 10),
        'guardian_attestation_version' => env('GUARDIAN_ATTESTATION_VERSION', 'draft-2026-11'),
    ],
];
