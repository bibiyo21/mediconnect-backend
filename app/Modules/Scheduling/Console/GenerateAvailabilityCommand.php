<?php

namespace App\Modules\Scheduling\Console;

use App\Modules\Scheduling\Jobs\RefreshDoctorAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * GenerateAvailabilitySlots (§24): nightly, extends every scheduled doctor's
 * rolling horizon by queueing one refresh each. `--sync` runs them here.
 */
final class GenerateAvailabilityCommand extends Command
{
    protected $signature = 'availability:generate {--doctor= : Only this doctor} {--sync : Run now instead of queueing}';

    protected $description = 'Refresh the availability projection for every doctor with an active schedule';

    public function handle(): int
    {
        $ids = DB::table('doctor_schedules')->where('status', 'ACTIVE')
            ->when($this->option('doctor'), fn ($q, $id) => $q->where('doctor_profile_id', (string) $id))
            ->distinct()->orderBy('doctor_profile_id')->pluck('doctor_profile_id');
        $started = microtime(true);
        foreach ($ids as $id) {
            $this->option('sync') ? RefreshDoctorAvailability::dispatchSync((string) $id) : RefreshDoctorAvailability::dispatch((string) $id);
        }
        $this->info(sprintf('%s %d doctors in %.1f s.', $this->option('sync') ? 'Refreshed' : 'Queued', $ids->count(), microtime(true) - $started));

        return self::SUCCESS;
    }
}
