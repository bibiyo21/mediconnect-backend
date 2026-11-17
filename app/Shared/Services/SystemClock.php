<?php

namespace App\Shared\Services;

use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;

/**
 * Real clock. Honours Carbon's test time (CarbonImmutable::setTestNow), so
 * tests can freeze time without swapping the binding.
 */
final class SystemClock implements Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
