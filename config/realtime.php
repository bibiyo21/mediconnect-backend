<?php

/*
 * Realtime reconciliation sweep (increment 5.6-0, `php artisan realtime:reconcile`).
 * Single Reverb node only: with several nodes the members call can 404 for a channel another node holds.
 */
return [
    'reconcile' => [
        // Stop listing members after this many seconds; the next run resumes from a rotating offset.
        'budget_seconds' => (int) env('REALTIME_RECONCILE_BUDGET_SECONDS', 40),
        // Consecutive runs whose Reverb API call failed before the High security event is raised.
        'failure_threshold' => 3,
        // Coverage gaps (a 404 on the members of a listed channel) make a run degraded only above this share of the
        // listed channels, and never below this count, so ordinary connect/disconnect churn does not alert.
        'gap_ratio' => 0.25,
        'gap_min_count' => 3,
        // Terminations one run may perform; the rest are found again next run. Counts against the time budget.
        'max_terminations_per_run' => 50,
        // The heartbeat is stale (readiness shows it) after this many seconds without a successful run.
        'stale_after_seconds' => 180,
    ],
];
