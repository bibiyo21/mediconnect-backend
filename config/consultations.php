<?php

/*
 * Consultations and care relationships (Phase 5). Validated when the application boots (ConsultationsConfig).
 */
return [
    // A walk-in care relationship lasts until the end of the visit's local day (the clinic's timezone) plus this many days.
    'care_access_days' => env('CONSULTATIONS_CARE_ACCESS_DAYS', 7),

    // How long a start or end waits for a competing row lock before it answers 409 CONCURRENT_MODIFICATION (milliseconds).
    'lock_timeout_ms' => env('CONSULTATIONS_LOCK_TIMEOUT_MS', 3000),

    'limits' => [
        // Start, end and complete, per user per minute.
        'write_per_minute' => env('CONSULTATIONS_WRITE_PER_MINUTE', 30),
    ],
];
