<?php

namespace App\Modules\WalkIns\Enums;

/**
 * Why an entry has a triage level above normal (decisions 88 to 91). Only the code is ever stored on events,
 * audit rows and broadcasts; the free-text note is encrypted on the entry and goes nowhere else.
 */
enum PriorityReason: string
{
    case SeniorPwd = 'SENIOR_PWD';
    case Pregnant = 'PREGNANT';
    case DoctorRequest = 'DOCTOR_REQUEST';
    case StaffConcern = 'STAFF_CONCERN';
    case Other = 'OTHER';
}
