<?php

namespace App\Modules\Scheduling\Jobs;

use App\Modules\Scheduling\Services\SlotHolds;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** ExpireSlotReservations (§24): every minute, ACTIVE holds past expiry become EXPIRED and their slots reopen. */
final class ExpireSlotReservations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onQueue('scheduling');
    }

    public function handle(SlotHolds $holds): void
    {
        while ($holds->expireDue() === 1000) {
            // keep going while full batches come back
        }
    }
}
