<?php

namespace App\Modules\Presence\Console;

use App\Modules\Presence\Jobs\AutoCheckOut;
use App\Modules\Presence\Services\AutoCheckOutCandidates;
use App\Shared\Contracts\Clock;
use Illuminate\Console\Command;

/** Queues the automatic check-out of every open session that is due (increment 5.8, D-L). Scheduled every five minutes; idempotent. */
final class AutoCheckOutCommand extends Command
{
    protected $signature = 'presence:auto-checkout';

    protected $description = 'Check out doctors still checked in after their clinic has closed, or from an earlier day (SYSTEM actor, HOLD only).';

    public function handle(AutoCheckOutCandidates $candidates, Clock $clock): int
    {
        $due = $candidates->due($clock->now());
        foreach ($due as $session) {
            AutoCheckOut::dispatch($session['session_id'], $session['doctor_profile_id'], $session['clinic_location_id']);
        }
        $this->info('presence:auto-checkout queued '.count($due));

        return self::SUCCESS;
    }
}
