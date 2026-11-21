<?php

namespace App\Modules\Scheduling\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public availability reads (increment 4.6). Reads the projection only and
 * never generates slots; applies the booking policy (notice, same day,
 * advance limit) per service mode. Advisory: holds revalidate (§11.6).
 */
final class AvailabilityReader
{
    public const MAX_DAYS = 31;

    public function __construct(private readonly SchedulingPolicies $policies) {}

    /**
     * @param  array{from?: string, to?: string, clinic_location_id?: string, doctor_service_id?: string, consultation_type?: string}  $filters
     * @return list<array<string, mixed>>
     */
    public function forDoctor(string $doctorId, array $filters): array
    {
        $tz = (string) config('mediconnect.timezone');
        $today = CarbonImmutable::now($tz)->toDateString();
        $from = max($filters['from'] ?? $today, $today);
        $to = $filters['to'] ?? CarbonImmutable::parse($from)->addDays(13)->toDateString();
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_DAYS - 1) {
            throw ValidationException::withMessages(['to' => ['Ask for at most '.self::MAX_DAYS.' days at a time.']]);
        }

        return AvailabilityCache::remember($doctorId, ['from' => $from, 'to' => $to, 'filters' => $filters], function () use ($doctorId, $from, $to, $filters, $today, $tz) {
            $rows = DB::table('doctor_availability_slots as a')
                ->join('doctor_service_modes as m', 'm.id', '=', 'a.doctor_service_mode_id')
                ->join('doctor_services as ds', 'ds.id', '=', 'm.doctor_service_id')
                ->leftJoin('clinic_locations as l', 'l.id', '=', 'a.clinic_location_id')
                ->where('a.doctor_profile_id', $doctorId)->where('a.slot_kind', 'APPOINTMENT')->where('a.status', 'OPEN')
                ->where('m.status', 'ACTIVE')->where('ds.status', 'ACTIVE')
                ->whereBetween('a.local_date', [$from, $to])
                ->when($filters['clinic_location_id'] ?? null, fn ($q, $id) => $q->where('a.clinic_location_id', $id))
                ->when($filters['doctor_service_id'] ?? null, fn ($q, $id) => $q->where('ds.id', $id))
                ->when($filters['consultation_type'] ?? null, fn ($q, $type) => $q->where('m.consultation_type', $type))
                ->orderBy('a.starts_at')->limit(2000)
                ->get(['a.id', 'a.starts_at', 'a.ends_at', 'a.local_date', 'a.clinic_location_id', 'a.doctor_service_mode_id', 'ds.id as doctor_service_id', 'ds.name as service_name', 'm.consultation_type', 'l.timezone']);

            $now = CarbonImmutable::now();
            $policyFor = [];
            $out = [];
            foreach ($rows as $r) {
                $policy = $policyFor[$r->doctor_service_mode_id] ??= $this->policies->resolve($doctorId, $r->clinic_location_id, (string) $r->doctor_service_mode_id);
                $starts = CarbonImmutable::parse((string) $r->starts_at);
                if ($starts->lt($now->addMinutes((int) $policy['min_booking_notice_minutes']))
                    || $starts->gt($now->addDays((int) $policy['max_advance_booking_days']))
                    || (! $policy['allow_same_day'] && $r->local_date === $today)) {
                    continue;
                }
                $out[] = [
                    'slot_id' => (string) $r->id,
                    'starts_at' => $starts->toIso8601ZuluString(),
                    'ends_at' => CarbonImmutable::parse((string) $r->ends_at)->toIso8601ZuluString(),
                    'local_date' => (string) $r->local_date,
                    'timezone' => (string) ($r->timezone ?? $tz),
                    'clinic_location_id' => $r->clinic_location_id,
                    'doctor_service_id' => (string) $r->doctor_service_id,
                    'service_name' => (string) $r->service_name,
                    'consultation_type' => (string) $r->consultation_type,
                ];
            }

            return $out;
        });
    }
}
