<?php

namespace App\Modules\Scheduling\DTOs;

use Carbon\CarbonImmutable;

/**
 * A half-open span [startsAt, endsAt) in UTC. $id is set only for a scheduled break (its doctor_schedule_breaks id), so a
 * caller can refer to it; every other span leaves it null.
 */
final class TimeWindow
{
    public function __construct(public readonly CarbonImmutable $startsAt, public readonly CarbonImmutable $endsAt, public readonly ?string $id = null) {}
}
