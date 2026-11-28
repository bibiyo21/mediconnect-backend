<?php

namespace App\Modules\Presence\Enums;

/**
 * Why a doctor checked out (increment 5.8). A fixed code, never free text. Matches the database CHECK on doctor_presence_sessions.checkout_reason
 * (a unit test keeps them equal).
 */
enum CheckOutReason: string
{
    case EndOfShift = 'END_OF_SHIFT';
    case Emergency = 'EMERGENCY';
    case Personal = 'PERSONAL';
    case ClinicClosed = 'CLINIC_CLOSED';
    case Other = 'OTHER';
}
