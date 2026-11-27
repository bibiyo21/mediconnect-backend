<?php

namespace App\Modules\Notifications\Realtime;

/** The kinds of channel the platform authorizes (routes/channels.php). */
enum ChannelKind: string
{
    case User = 'user';
    case Doctor = 'doctor';
    case Clinic = 'clinic';
    case ClinicQueue = 'clinic_queue';
}
