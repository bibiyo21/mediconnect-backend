<?php

namespace App\Modules\Scheduling\Contracts;

/**
 * Open, bookable times for search documents (increment 4.8). Policy-filtered
 * like the public availability read, so search never shows a time the booking
 * notice would refuse. Advisory only: a hold always revalidates (§11.6).
 */
interface AvailabilitySummaries
{
    /**
     * Start times of open appointment slots in the next $days days, keyed by
     * clinic location id, or "online" for online consultations.
     *
     * @return array<string, list<int>> unix timestamps, ascending
     */
    public function openStartTimes(string $doctorId, int $days): array;
}
