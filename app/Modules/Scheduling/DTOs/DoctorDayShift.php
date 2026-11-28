<?php

namespace App\Modules\Scheduling\DTOs;

use Carbon\CarbonImmutable;

/**
 * One shift of a doctor's day at a location (increment 5.9-B, M2): its id, its service mode and its working window before breaks (the span
 * after exceptions and special hours, UTC). $breaks are its scheduled breaks inside those hours; each carries its doctor_schedule_breaks id.
 */
final class DoctorDayShift
{
    /** @param list<TimeWindow> $breaks */
    public function __construct(
        public readonly string $id,
        public readonly string $serviceMode,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly array $breaks,
    ) {}
}
