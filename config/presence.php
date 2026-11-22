<?php

/*
 * Doctor presence (Phase 5, architecture.md §9). Technical limits only; who may check in
 * and when is decided by assignments, service modes and clinic hours in PostgreSQL.
 */
return [
    // A doctor may check in this many minutes before a shift starts, while the clinic is still closed.
    'checkin_early_minutes' => (int) env('PRESENCE_CHECKIN_EARLY_MINUTES', 60),

    // The longest break a doctor or staff member may announce (the reminder fires at the expected end).
    'max_break_minutes' => (int) env('PRESENCE_MAX_BREAK_MINUTES', 240),

    // How long a change waits for a competing transaction's row lock before answering 409 (never a long hang).
    'lock_timeout_ms' => (int) env('PRESENCE_LOCK_TIMEOUT_MS', 3000),
];
