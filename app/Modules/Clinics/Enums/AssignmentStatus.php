<?php

namespace App\Modules\Clinics\Enums;

/** A doctor's assignment to a clinic location (§6). PENDING until the doctor accepts. */
enum AssignmentStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Ended = 'ENDED';
}
