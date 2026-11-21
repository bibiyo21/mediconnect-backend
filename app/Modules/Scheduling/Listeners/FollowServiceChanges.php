<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\Doctors\Events\DoctorServiceReplaced;
use App\Modules\Doctors\Events\DoctorServicesEnded;
use App\Modules\Scheduling\Services\ServiceModes;

/** Keeps service modes on the current service row (synchronous, inside the Doctors transaction). */
final class FollowServiceChanges
{
    public function __construct(private readonly ServiceModes $modes) {}

    public function replaced(DoctorServiceReplaced $event): void
    {
        $this->modes->follow($event->doctorProfileId, $event->previousServiceId, $event->serviceId);
    }

    public function ended(DoctorServicesEnded $event): void
    {
        $this->modes->deactivate($event->doctorProfileId, $event->serviceIds);
    }
}
