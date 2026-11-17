<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled tasks (run by the scheduler container: php artisan schedule:work).
 */
Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('idempotency:purge')->hourly()->withoutOverlapping();
// Idempotent: creates the next months' audit partitions if missing, and fails loudly
// if rows ever landed in the default partition.
Schedule::command('audit:partitions')->dailyAt('01:15')->withoutOverlapping();
