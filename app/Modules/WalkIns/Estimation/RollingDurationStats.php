<?php

namespace App\Modules\WalkIns\Estimation;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Shared\Contracts\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How long a doctor's consultations of one service really take (architecture.md §10.4): a typical value
 * over the most recent completed ones within the configured age. With too little history the service's own
 * duration_minutes is used, and then the configured default.
 *
 * A consultation counts toward a service when:
 *  - its appointment was booked for any row of the service's replaces_id chain, so a fee change (which ends
 *    the row and starts a replacement) does not lose the history; or
 *  - it was a walk-in registered for any row of the chain (walkin_queue_entries.doctor_service_id), which
 *    follows the same replaces_id chain, so the walk-in history of a service is exactly its own.
 */
final class RollingDurationStats
{
    /** Below this many samples the median is used: a trimmed mean of so few values is mostly the outlier. */
    private const MEDIAN_BELOW = 5;

    public function __construct(private readonly Clock $clock) {}

    public function expectedFor(string $doctorProfileId, ?string $doctorServiceId): ExpectedDuration
    {
        $config = EstimationConfig::fromConfig();
        $service = $doctorServiceId === null ? null : DoctorService::query()->where('doctor_profile_id', $doctorProfileId)->find($doctorServiceId);

        $samples = $this->samples($doctorProfileId, $service, $config);
        if (count($samples) >= $config->minSamples) {
            return new ExpectedDuration(self::typicalSeconds($samples, $config->trimFraction), DurationSource::Rolling, count($samples));
        }
        if ($service !== null && $service->duration_minutes > 0) {
            return new ExpectedDuration($service->duration_minutes * 60, DurationSource::Service);
        }

        return new ExpectedDuration($config->defaultMinutes * 60, DurationSource::Default);
    }

    /**
     * The typical duration: the median of fewer than 5 values, otherwise the mean after cutting $trim of the
     * values (at least one) off each end. Rounded to whole seconds.
     *
     * @param  list<int>  $seconds
     */
    public static function typicalSeconds(array $seconds, float $trim): int
    {
        sort($seconds);
        $count = count($seconds);
        if ($count === 0) {
            throw new \InvalidArgumentException('No durations given.');
        }
        if ($count < self::MEDIAN_BELOW) {
            $mid = intdiv($count, 2);

            return $count % 2 === 1 ? $seconds[$mid] : (int) round(($seconds[$mid - 1] + $seconds[$mid]) / 2);
        }
        $cut = $trim > 0 ? max(1, (int) floor($count * min(0.49, $trim))) : 0;
        $kept = array_slice($seconds, $cut, $count - 2 * $cut);

        return (int) round(array_sum($kept) / count($kept));
    }

    /**
     * Every doctor_services row in the replaces_id chain of a service, oldest to newest, as DoctorService models.
     *
     * @return list<DoctorService>
     */
    private function chain(DoctorService $service): array
    {
        $rows = DB::select('WITH RECURSIVE up(id, replaces_id) AS (
                SELECT id, replaces_id FROM doctor_services WHERE id = ?
                UNION
                SELECT s.id, s.replaces_id FROM doctor_services s JOIN up ON s.id = up.replaces_id
            ), chain(id) AS (
                SELECT id FROM up WHERE replaces_id IS NULL
                UNION
                SELECT s.id FROM doctor_services s JOIN chain ON s.replaces_id = chain.id
            ) SELECT id FROM chain', [$service->id]);
        $ids = array_map(fn ($r) => (string) $r->id, $rows);
        if (! in_array($service->id, $ids, true)) {
            $ids[] = $service->id;
        }

        return array_values(DoctorService::query()->where('doctor_profile_id', $service->doctor_profile_id)->whereIn('id', $ids)->get()->all());
    }

    /**
     * Durations of the latest completed consultations within the age limit, newest first.
     *
     * @return list<int>
     */
    private function samples(string $doctorProfileId, ?DoctorService $service, EstimationConfig $config): array
    {
        $query = Consultation::query()
            ->where('doctor_profile_id', $doctorProfileId)
            ->where('status', ConsultationStatus::Completed)
            ->where('duration_seconds', '>', 0)
            ->where('ended_at', '>=', $this->clock->now()->subDays($config->maxAgeDays));

        if ($service !== null) {
            $chain = $this->chain($service);
            $ids = array_map(fn (DoctorService $s) => $s->id, $chain);
            $query->where(function (Builder $match) use ($ids) {
                $match->whereIn('appointment_id', Appointment::query()->whereIn('doctor_service_id', $ids)->select('id'))
                    ->orWhereIn('walkin_queue_entry_id', WalkInQueueEntry::query()->whereIn('doctor_service_id', $ids)->select('id'));
            });
        }

        return array_values(array_map('intval', $query->orderByDesc('ended_at')->orderByDesc('id')->limit($config->windowSize)->pluck('duration_seconds')->all()));
    }
}
