<?php

namespace App\Modules\Scheduling\Jobs;

use App\Modules\Scheduling\Services\AvailabilityProjector;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rebuilds one doctor's availability over the policy horizon (§24 job list).
 * Unique per doctor until it starts: changes made while it waits are read by
 * the run itself.
 */
final class RefreshDoctorAvailability implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $doctorId)
    {
        $this->onQueue('scheduling');
    }

    public function uniqueId(): string
    {
        return $this->doctorId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(AvailabilityProjector $projector): void
    {
        $projector->refresh($this->doctorId);
    }
}
