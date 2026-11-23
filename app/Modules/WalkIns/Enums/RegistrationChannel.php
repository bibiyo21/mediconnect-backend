<?php

namespace App\Modules\WalkIns\Enums;

/**
 * How an entry was registered. The server derives it from the caller; the API never accepts it from the client.
 * KIOSK was cut from Phase 5 and DOCTOR_APP stays inert until the owner approves doctor registration (A2):
 * both values are allowed by the database but nothing writes them yet.
 */
enum RegistrationChannel: string
{
    case PatientApp = 'PATIENT_APP';
    case StaffConsole = 'STAFF_CONSOLE';
    case Kiosk = 'KIOSK';
    case DoctorApp = 'DOCTOR_APP';
}
