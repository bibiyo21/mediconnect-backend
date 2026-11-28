<?php

namespace App\Modules\WalkIns\Exceptions;

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** Staff or doctor registration over capacity: 409 with the counts; resend with capacity_override (audited), decision 151. */
final class CapacityOverrideRequired extends DomainException
{
    public function __construct(public readonly int $current, public readonly int $capacity)
    {
        parent::__construct();
    }

    public function report(): bool
    {
        return true;
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CapacityOverrideRequired;
    }

    public function errors(): array
    {
        return ['current' => [(string) $this->current], 'capacity' => [(string) $this->capacity]];
    }
}
