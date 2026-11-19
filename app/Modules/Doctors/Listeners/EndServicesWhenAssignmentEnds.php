<?php

namespace App\Modules\Doctors\Listeners;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Events\DoctorAssignmentEnded;
use App\Modules\Doctors\Services\DoctorServices;

/** A doctor who no longer practises at a location stops offering services there. */
final class EndServicesWhenAssignmentEnds
{
    public function __construct(private readonly DoctorServices $services, private readonly RecordsAudit $audit) {}

    public function handle(DoctorAssignmentEnded $event): void
    {
        $count = $this->services->endAtLocation($event->doctorProfileId, $event->clinicLocationId);
        if ($count > 0) {
            $this->audit->record('doctor.services_ended_with_assignment', 'doctor_profile', $event->doctorProfileId, ['count' => $count, 'clinic_location_id' => $event->clinicLocationId]);
        }
    }
}
