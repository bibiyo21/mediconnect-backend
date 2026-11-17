<?php

namespace App\Modules\Audit\Enums;

enum Severity: string
{
    case Info = 'INFO';
    case Warning = 'WARNING';
    case High = 'HIGH';
}
