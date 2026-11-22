<?php

namespace App\Modules\Presence\Enums;

/** How a presence change was made (CLAUDE.md §8.5 "change method"). */
enum PresenceChangeMethod: string
{
    /** The doctor, from their own session. */
    case DoctorApp = 'DOCTOR_APP';
    /** A clinic member acting on the doctor's behalf. */
    case StaffConsole = 'STAFF_CONSOLE';
    /** The platform itself (auto check-out); no acting user. */
    case System = 'SYSTEM';
    /** A platform administrator acting on the doctor's behalf. */
    case Admin = 'ADMIN';
}
