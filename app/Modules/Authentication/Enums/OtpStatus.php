<?php

namespace App\Modules\Authentication\Enums;

enum OtpStatus: string
{
    case Pending = 'PENDING';
    case Verified = 'VERIFIED';
    case Failed = 'FAILED';
    case Consumed = 'CONSUMED';
}
