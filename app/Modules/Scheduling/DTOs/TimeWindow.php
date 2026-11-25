<?php

namespace App\Modules\Scheduling\DTOs;

use Carbon\CarbonImmutable;

/** A half-open span [startsAt, endsAt) in UTC. */
final class TimeWindow
{
    public function __construct(public readonly CarbonImmutable $startsAt, public readonly CarbonImmutable $endsAt) {}
}
