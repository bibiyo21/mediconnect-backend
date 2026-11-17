<?php

namespace App\Modules\Authentication\Enums;

enum DevicePlatform: string
{
    case Ios = 'IOS';
    case Android = 'ANDROID';
    case Web = 'WEB';
}
