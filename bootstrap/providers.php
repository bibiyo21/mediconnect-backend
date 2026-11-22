<?php

use App\Modules\Administration\Providers\AdministrationServiceProvider;
use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Authentication\Providers\AuthenticationServiceProvider;
use App\Modules\Clinics\Providers\ClinicsServiceProvider;
use App\Modules\Discovery\Providers\DiscoveryServiceProvider;
use App\Modules\Doctors\Providers\DoctorsServiceProvider;
use App\Modules\Notifications\Providers\NotificationsServiceProvider;
use App\Modules\Patients\Providers\PatientsServiceProvider;
use App\Modules\Presence\Providers\PresenceServiceProvider;
use App\Modules\Rbac\Providers\RbacServiceProvider;
use App\Modules\Scheduling\Providers\SchedulingServiceProvider;
use App\Modules\Specialties\Providers\SpecialtiesServiceProvider;
use App\Modules\Users\Providers\UsersServiceProvider;
use App\Modules\WalkIns\Providers\WalkInsServiceProvider;
use App\Providers\AppServiceProvider;

// Module providers are added as each module is implemented (C-3: nothing is
// registered for modules of later phases).
return [
    AppServiceProvider::class,
    AuditServiceProvider::class,
    UsersServiceProvider::class,
    PatientsServiceProvider::class,
    RbacServiceProvider::class,
    NotificationsServiceProvider::class,
    AuthenticationServiceProvider::class,
    AdministrationServiceProvider::class,
    SpecialtiesServiceProvider::class,
    ClinicsServiceProvider::class,
    DoctorsServiceProvider::class,
    DiscoveryServiceProvider::class,
    SchedulingServiceProvider::class,
    PresenceServiceProvider::class,
    WalkInsServiceProvider::class,
];
