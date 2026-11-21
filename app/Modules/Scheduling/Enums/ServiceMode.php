<?php

namespace App\Modules\Scheduling\Enums;

/** How patients reach a doctor for one service at one location (§7.1). */
enum ServiceMode: string
{
    case Appointment = 'APPOINTMENT';
    case WalkIn = 'WALK_IN';
    case Both = 'BOTH';
}
