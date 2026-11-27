<?php

use App\Modules\Scheduling\Jobs\ExpireSlotReservations;
use App\Modules\WalkIns\Jobs\PruneFailedHints;
use App\Modules\WalkIns\Jobs\PurgeGuestWalkInPii;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled tasks (run by the scheduler container: php artisan schedule:work).
 */
Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('idempotency:purge')->hourly()->withoutOverlapping();
// Idempotent: creates the next months' audit partitions if missing, and fails loudly
// if rows ever landed in the default partition.
Schedule::command('audit:partitions')->dailyAt('01:15')->withoutOverlapping();
Schedule::command('accounts:anonymize-deleted')->dailyAt('02:30')->withoutOverlapping(); // D-38
// Search counts (doctors per clinic and specialty) drift slowly; a nightly rebuild corrects them (§12.3).
Schedule::command('search:partitions')->dailyAt('01:20')->withoutOverlapping(); // 13-month retention
Schedule::command('search:rebuild specialties')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('search:rebuild clinics')->dailyAt('03:10')->withoutOverlapping();
// Availability horizon (Phase 4, §24 GenerateAvailabilitySlots): every scheduled doctor, nightly.
Schedule::command('availability:generate')->dailyAt('02:30')->withoutOverlapping();
// Expired holds back to open slots (Phase 4, §24 ExpireSlotReservations).
Schedule::job(new ExpireSlotReservations)->everyMinute();
// Guest walk-in data (A5, plan Q6): names, phones, typed HMO names and priority notes are cleared after the retention period.
Schedule::job(new PurgeGuestWalkInPii)->dailyAt('03:30');
// Failed walk-in realtime hints older than a day (M-1): allowlisted classes only, never a global prune.
Schedule::job(new PruneFailedHints)->dailyAt('03:45');
// Realtime sweep (increment 5.6-0): closes sockets whose authorization or credential ended without a
// terminate call. A command, not a queued job, so each tick starts with no stale model or permission state.
Schedule::command('realtime:reconcile')->everyMinute()->withoutOverlapping(5)->onOneServer()->runInBackground();
