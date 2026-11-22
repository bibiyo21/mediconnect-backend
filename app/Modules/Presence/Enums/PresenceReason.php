<?php

namespace App\Modules\Presence\Enums;

/**
 * Why a presence change was made: a fixed code, never free text (free text can carry personal or
 * clinical detail and ends up in event rows and logs). Shown to the clinic's staff only; never public.
 */
enum PresenceReason: string
{
    case ShiftStart = 'SHIFT_START';
    case LateArrival = 'LATE_ARRIVAL';
    case CoveringColleague = 'COVERING_COLLEAGUE';
    case AdminWork = 'ADMIN_WORK';
    case PhoneCall = 'PHONE_CALL';
    case Emergency = 'EMERGENCY';
    case Personal = 'PERSONAL';
    case Other = 'OTHER';
}
