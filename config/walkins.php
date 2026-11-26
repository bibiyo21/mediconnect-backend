<?php

/*
 * Walk-in queues (Phase 5, architecture.md §10). Estimation knobs only for now; they tune
 * how a waiting range is built and never turn an estimate into a promise (CLAUDE.md §9.4).
 * Values are validated when the application boots (EstimationConfig): a bad value stops the boot.
 */
return [
    'estimation' => [
        // How many of a doctor's most recent completed consultations (per service) feed the rolling average.
        'window_size' => (int) env('WALKINS_ESTIMATE_WINDOW_SIZE', 20),

        // Fraction cut from each end of the sorted durations before averaging (0 to 0.49), so one very short or very long visit does not skew it.
        'trim_fraction' => (float) env('WALKINS_ESTIMATE_TRIM_FRACTION', 0.1),

        // Fewer completed consultations than this (and at most window_size) and the service's own duration_minutes is used instead.
        'min_samples' => (int) env('WALKINS_ESTIMATE_MIN_SAMPLES', 3),

        // Consultations that ended longer ago than this are not counted (a doctor's pace and services change).
        'max_age_days' => (int) env('WALKINS_ESTIMATE_MAX_AGE_DAYS', 180),

        // Used when there is no history and no service duration to fall back on.
        'default_minutes' => (int) env('WALKINS_ESTIMATE_DEFAULT_MINUTES', 15),

        // The range runs from (100 - this)% to (100 + this)% of each uncertain consultation duration.
        'range_spread_percent' => (int) env('WALKINS_ESTIMATE_RANGE_SPREAD_PERCENT', 25),

        // No estimate is shown narrower than this many minutes.
        'min_range_width_minutes' => (int) env('WALKINS_ESTIMATE_MIN_RANGE_WIDTH_MINUTES', 5),

        // A fixed return time (break end, announced return, shift start) is the earliest the doctor can be back; the
        // range allows for lateness of spread% of the time away, but never more than this many minutes.
        'max_lateness_minutes' => (int) env('WALKINS_ESTIMATE_MAX_LATENESS_MINUTES', 30),

        // How long a live appointment stays protected from its start while the patient may still arrive (D-22's proposed
        // starting value, not owner-approved). Used only when scheduling_policies sets no late_grace_minutes. No effect
        // until Phase 6 creates appointments.
        'appointment_grace_minutes' => (int) env('WALKINS_APPOINTMENT_GRACE_MINUTES', 15),
    ],

    // Guest walk-ins (A5, plan Q6): days a guest's name, phone, typed HMO card name and priority note are kept. A daily job
    // clears them; the entry itself stays. The figure is a proposal: the owner and counsel confirm it.
    'guest_pii_retention_days' => (int) env('WALKINS_GUEST_PII_RETENTION_DAYS', 30),

    // Remote (patient app) registration limits (A7). Staff are never limited. NO_SHOW never feeds a restriction.
    'remote' => [
        // Live entries (REGISTERED, WAITING, CALLED, IN_CONSULTATION, SKIPPED) one patient profile may hold on one day.
        'max_live_entries_per_patient' => (int) env('WALKINS_REMOTE_MAX_LIVE_PER_PATIENT', 2),
        // Distinct patient profiles one account may register from the app on one day.
        'max_patients_per_account_per_day' => (int) env('WALKINS_REMOTE_MAX_PATIENTS_PER_ACCOUNT', 5),
    ],

    // Named rate limiters, requests per minute per user (decision 156). No exemption for shared accounts.
    'limits' => [
        'register_patient_per_minute' => (int) env('WALKINS_LIMIT_REGISTER_PATIENT', 10),
        'register_staff_per_minute' => (int) env('WALKINS_LIMIT_REGISTER_STAFF', 60),
        'write_per_minute' => (int) env('WALKINS_LIMIT_WRITE', 60),
        'search_per_minute' => (int) env('WALKINS_LIMIT_SEARCH', 30),
        'read_per_minute' => (int) env('WALKINS_LIMIT_READ', 120),
    ],

    // A registration waits this long for a competing row or advisory lock, then answers 409 CONCURRENT_MODIFICATION.
    // Real-time hints (decisions 266-270): queue-level hints are debounced per queue.
    'realtime' => ['debounce_seconds' => (int) env('WALKINS_REALTIME_DEBOUNCE_SECONDS', 2)],

    'lock_timeout_ms' => (int) env('WALKINS_LOCK_TIMEOUT_MS', 3000),

    // Name search over a location's live entries decrypts in memory; this caps the rows read.
    'search_max_rows' => (int) env('WALKINS_SEARCH_MAX_ROWS', 500),

    // The staff board shows minutes for this many waiting entries (walk-in-management §9.5).
    'board_minutes_for_next' => (int) env('WALKINS_BOARD_MINUTES_FOR_NEXT', 4),

    // The privacy notice shown to a guest at the desk (A5). A registration must name one of these versions; the wording itself is counsel's.
    'privacy_notice' => [
        'published_versions' => array_values(array_filter(array_map('trim', explode(',', (string) env('WALKINS_PRIVACY_NOTICE_VERSIONS', 'v1'))))),
    ],
];
