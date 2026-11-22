<?php

/*
 * Scheduling (Phase 4, architecture.md §8). Booking rules themselves live in
 * scheduling_policies; this file holds technical limits only.
 */
return [
    // How long a hold waits for a competing transaction's row or constraint lock before answering 409.
    'hold_lock_timeout_ms' => (int) env('SCHEDULING_HOLD_LOCK_TIMEOUT_MS', 3000),
];
