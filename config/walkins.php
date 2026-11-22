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
    ],
];
