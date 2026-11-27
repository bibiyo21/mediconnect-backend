<?php

namespace App\Modules\Consultations\Enums;

/**
 * How much of a consultation a reader sees (rbac-permissions §8, increment 5.7-2). Neither view carries a name or any clinical content.
 * Full: ids, times, the patient profile id and the participant roles. Restricted: the place, the status and the source ids only.
 */
enum ConsultationView: string
{
    case Full = 'FULL';
    case Restricted = 'RESTRICTED';
}
