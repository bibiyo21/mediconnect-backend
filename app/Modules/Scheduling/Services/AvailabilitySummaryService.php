<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Contracts\AvailabilitySummaries;
use Carbon\CarbonImmutable;

final class AvailabilitySummaryService implements AvailabilitySummaries
{
    public function __construct(private readonly AvailabilityReader $reader) {}

    public function openStartTimes(string $doctorId, int $days): array
    {
        $today = CarbonImmutable::now((string) config('mediconnect.timezone'));
        $days = max(1, min($days, AvailabilityReader::MAX_DAYS));
        $out = [];
        foreach ($this->reader->load($doctorId, $today->toDateString(), $today->addDays($days - 1)->toDateString()) as $slot) {
            $key = $slot['consultation_type'] === 'ONLINE' ? 'online' : (string) $slot['clinic_location_id'];
            $out[$key][] = CarbonImmutable::parse((string) $slot['starts_at'])->getTimestamp();
        }

        return $out;
    }
}
