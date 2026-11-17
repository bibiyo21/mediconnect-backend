<?php

namespace App\Shared\Enums;

enum DeliveryChannel: string
{
    case Email = 'EMAIL';
    case Sms = 'SMS';
}
