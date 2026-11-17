<?php

namespace App\Modules\Patients\Enums;

enum PatientStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
