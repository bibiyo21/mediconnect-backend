<?php

/*
 * Consultations and care relationships (Phase 5). Validated when the application boots (ConsultationsConfig).
 */
return [
    // A walk-in care relationship lasts until the end of the visit's local day (the clinic's timezone) plus this many days.
    'care_access_days' => env('CONSULTATIONS_CARE_ACCESS_DAYS', 7),
];
