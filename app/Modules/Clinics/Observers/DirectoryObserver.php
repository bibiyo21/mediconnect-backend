<?php

namespace App\Modules\Clinics\Observers;

use App\Modules\Clinics\Events\ClinicDirectoryChanged;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicHoliday;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\ClinicOperatingHour;
use App\Modules\Clinics\Models\ClinicService;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Doctors\Events\DoctorDirectoryChanged;
use App\Shared\Services\Outbox;
use Illuminate\Database\Eloquent\Model;

/**
 * Records ClinicDirectoryChanged when public clinic data changes (clinic,
 * location, clinic service), and also DoctorDirectoryChanged when an
 * assignment changes, so a doctor who leaves a clinic is reindexed too.
 */
final class DirectoryObserver
{
    public function __construct(private readonly Outbox $outbox) {}

    public function saved(Model $model): void
    {
        $this->record($model);
    }

    public function deleted(Model $model): void
    {
        $this->record($model);
    }

    private function record(Model $model): void
    {
        $clinicId = match (true) {
            $model instanceof Clinic => $model->id,
            $model instanceof ClinicLocation => $model->clinic_id,
            $model instanceof ClinicService => ClinicLocation::whereKey($model->clinic_location_id)->value('clinic_id'),
            $model instanceof DoctorClinicAssignment => ClinicLocation::whereKey($model->clinic_location_id)->value('clinic_id'),
            // Opening hours and holidays feed availability (Phase 4): the scheduling refresh listens for this event.
            $model instanceof ClinicOperatingHour => ClinicLocation::whereKey($model->clinic_location_id)->value('clinic_id'),
            $model instanceof ClinicHoliday => $model->getAttribute('clinic_id'),
            default => null,
        };
        if (is_string($clinicId)) {
            $this->outbox->record(new ClinicDirectoryChanged($clinicId));
        }
        if ($model instanceof DoctorClinicAssignment) {
            $this->outbox->record(new DoctorDirectoryChanged($model->doctor_profile_id));
        }
    }
}
