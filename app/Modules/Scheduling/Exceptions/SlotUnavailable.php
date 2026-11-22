<?php

namespace App\Modules\Scheduling\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** The time is taken, held or no longer offered (409 SLOT_UNAVAILABLE). Counted as DOUBLE_BOOKING_REJECTIONS. */
final class SlotUnavailable extends DomainException
{
    public function __construct(string $message = 'This time is no longer available. Please choose another.')
    {
        parent::__construct($message);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::SlotUnavailable;
    }
}
