<?php

namespace App\Shared\Contracts;

use Carbon\CarbonImmutable;

/**
 * Current time, injected so time-dependent rules (holds, expiry, grace
 * periods, grant windows) can be tested without sleeping. Always UTC.
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
