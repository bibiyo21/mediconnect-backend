<?php

/*
 * Market defaults (CLAUDE.md §1.2). Change here, not in code.
 */
return [
    // Local dates (birth dates, "today") are judged in the market's timezone.
    'timezone' => env('MEDICONNECT_TIMEZONE', 'Asia/Manila'),

    // D-37: account holders must be adults; minors are dependents of a guardian.
    'minimum_account_age' => (int) env('MEDICONNECT_MINIMUM_ACCOUNT_AGE', 18),
];
