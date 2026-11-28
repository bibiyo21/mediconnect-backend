<?php

namespace App\Modules\WalkIns\Console;

use App\Modules\WalkIns\Services\PastDaySweep;
use App\Shared\Contracts\Clock;
use Illuminate\Console\Command;

/** Closes walk-in queues from an earlier local day (increment 5.8, D-O). Scheduled hourly, so each location's midnight is covered; idempotent. */
final class ClosePastDaysCommand extends Command
{
    protected $signature = 'walkins:close-past-days';

    protected $description = 'Cancel the open walk-in entries of earlier days and close their queues (never CALLED or IN_CONSULTATION).';

    public function handle(PastDaySweep $sweep, Clock $clock): int
    {
        $closed = $sweep->run($clock->now());
        $this->info("walkins:close-past-days closed {$closed} queue(s)");

        return self::SUCCESS;
    }
}
