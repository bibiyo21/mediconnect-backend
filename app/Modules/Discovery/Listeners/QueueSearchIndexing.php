<?php

namespace App\Modules\Discovery\Listeners;

use App\Modules\Clinics\Events\ClinicDirectoryChanged;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Discovery\Jobs\IndexClinicSearchDocument;
use App\Modules\Discovery\Jobs\IndexDoctorSearchDocument;
use App\Modules\Discovery\Jobs\IndexSpecialtySearchDocument;
use App\Modules\Doctors\Events\DoctorDirectoryChanged;
use App\Modules\Doctors\Models\DoctorSpecialty;
use App\Modules\Specialties\Events\SpecialtyCatalogChanged;

/**
 * Turns directory events (from the outbox relay) into index jobs on the
 * `search` queue. A change can affect other documents: a clinic rename shows
 * on its doctors' documents, a doctor change moves clinic and specialty counts.
 */
final class QueueSearchIndexing
{
    public function handleDoctor(DoctorDirectoryChanged $event): void
    {
        IndexDoctorSearchDocument::dispatch($event->id);
        $clinicIds = ClinicLocation::whereIn('id', DoctorClinicAssignment::where('doctor_profile_id', $event->id)->where('status', 'ACTIVE')->select('clinic_location_id'))->pluck('clinic_id')->unique();
        foreach ($clinicIds as $clinicId) {
            IndexClinicSearchDocument::dispatch((string) $clinicId);
        }
        foreach (DoctorSpecialty::where('doctor_profile_id', $event->id)->pluck('specialty_id')->unique() as $specialtyId) {
            IndexSpecialtySearchDocument::dispatch((string) $specialtyId);
        }
    }

    public function handleClinic(ClinicDirectoryChanged $event): void
    {
        IndexClinicSearchDocument::dispatch($event->id);
        $locations = ClinicLocation::where('clinic_id', $event->id)->select('id');
        foreach (DoctorClinicAssignment::whereIn('clinic_location_id', $locations)->where('status', 'ACTIVE')->pluck('doctor_profile_id')->unique() as $doctorId) {
            IndexDoctorSearchDocument::dispatch((string) $doctorId);
        }
    }

    public function handleSpecialty(SpecialtyCatalogChanged $event): void
    {
        IndexSpecialtySearchDocument::dispatch($event->id);
        foreach (DoctorSpecialty::where('specialty_id', $event->id)->where('verification_status', 'CONFIRMED')->pluck('doctor_profile_id')->unique() as $doctorId) {
            IndexDoctorSearchDocument::dispatch((string) $doctorId);
        }
    }
}
