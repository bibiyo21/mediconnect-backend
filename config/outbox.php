<?php

use App\Modules\Clinics\Events\ClinicDirectoryChanged;
use App\Modules\Doctors\Events\DoctorDirectoryChanged;
use App\Modules\Specialties\Events\SpecialtyCatalogChanged;

/*
 * Domain events that may be written to the transactional outbox, by stable
 * event type. An event class must be registered here before it can be
 * recorded, so a typo cannot create events nobody can read back.
 */
return [
    // After this many failed attempts an event is parked (failed_at) and an alert is raised.
    'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 10),

    'events' => [
        // Search indexing (Phase 3, architecture.md §12.3)
        'doctors.directory_changed' => DoctorDirectoryChanged::class,
        'clinics.directory_changed' => ClinicDirectoryChanged::class,
        'specialties.catalog_changed' => SpecialtyCatalogChanged::class,
    ],
];
