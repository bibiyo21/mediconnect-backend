<?php

namespace App\Modules\Users\Enums;

enum UserStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Deactivated = 'DEACTIVATED';
}
