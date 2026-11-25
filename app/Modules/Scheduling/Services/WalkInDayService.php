<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Contracts\WalkInDay;
use App\Modules\Scheduling\DTOs\TimeWindow;
use App\Modules\Scheduling\DTOs\WalkInDayFacts;
use App\Modules\Scheduling\Slots\AvailabilityInputs;
use App\Modules\Scheduling\Slots\HolidayInput;
use App\Modules\Scheduling\Slots\Intervals;
use App\Modules\Scheduling\Slots\ShiftInput;
use App\Modules\Scheduling\Slots\SlotGenerator;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One doctor's walk-in day at one location, derived from PostgreSQL through the slot generator (increment 5.5a). One doctor and one
 * local date per call: cheap enough for the registration path, never run across doctors on a search (CLAUDE.md §32.5).
 *
 * Capacity per shift (decision 100): the shift's walkin_capacity when set, otherwise the walk-in window minutes divided by the
 * shift's slot length plus buffer, rounded down. The queue's capacity is the sum. A shift with no effective hours that day
 * (a closure, leave, a clinic holiday) contributes nothing, even with a walkin_capacity set.
 */
final class WalkInDayService implements WalkInDay
{
    private const LIVE_APPOINTMENT = ['PENDING_PAYMENT', 'PENDING_CONFIRMATION', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS', 'INTERRUPTED'];

    public function __construct(
        private readonly AvailabilityInputs $inputs,
        private readonly SlotGenerator $generator,
        private readonly SchedulingPolicies $policies,
        private readonly Clock $clock,
    ) {}

    public function offersWalkIn(string $doctorServiceId, string $localDate): bool
    {
        return DB::table('doctor_service_modes as m')->join('doctor_services as ds', 'ds.id', '=', 'm.doctor_service_id')
            ->where('m.doctor_service_id', $doctorServiceId)->where('m.status', 'ACTIVE')->where('ds.status', 'ACTIVE')
            ->where('m.consultation_type', 'IN_PERSON')->whereIn('m.service_mode', ['WALK_IN', 'BOTH'])
            ->where('m.effective_from', '<=', $localDate)->where(fn ($q) => $q->whereNull('m.effective_until')->orWhere('m.effective_until', '>=', $localDate))
            ->exists();
    }

    public function forDoctorAtLocation(string $doctorId, string $locationId, string $localDate, ?string $clinicServiceId = null, bool $anyClinicService = false, int $graceFallbackMinutes = 15): WalkInDayFacts
    {
        $location = DB::table('clinic_locations')->where('id', $locationId)->first(['clinic_id', 'timezone']);
        $timezone = $location === null ? (string) config('mediconnect.timezone') : (string) $location->timezone;
        $dayStart = CarbonImmutable::parse($localDate, $timezone)->startOfDay()->utc();
        $dayEnd = $dayStart->addDay();
        $dow = CarbonImmutable::parse($localDate)->dayOfWeekIso;

        [$clinicClosed, $clinicCloses] = $location === null ? [true, null] : $this->clinicHours((string) $location->clinic_id, $locationId, $localDate, $dow, $timezone);

        $modeIds = DB::table('doctor_service_modes as m')->join('doctor_services as ds', 'ds.id', '=', 'm.doctor_service_id')
            ->where('m.doctor_profile_id', $doctorId)->where('m.clinic_location_id', $locationId)->where('m.status', 'ACTIVE')->where('ds.status', 'ACTIVE')
            ->where('m.consultation_type', 'IN_PERSON')->whereIn('m.service_mode', ['WALK_IN', 'BOTH'])
            ->where('m.effective_from', '<=', $localDate)->where(fn ($q) => $q->whereNull('m.effective_until')->orWhere('m.effective_until', '>=', $localDate))
            ->when(! $anyClinicService, fn ($q) => $q->whereRaw('ds.clinic_service_id IS NOT DISTINCT FROM ?', [$clinicServiceId]))
            ->pluck('m.id')->map(fn ($id) => (string) $id)->all();

        $in = $this->inputs->load($doctorId, $localDate, $localDate);
        /** @var list<ShiftInput> $shifts */
        $shifts = array_values(array_filter($in['shifts'], fn (ShiftInput $s) => $s->locationId === $locationId && in_array($s->modeId, $modeIds, true) && in_array($s->serviceMode, ['WALK_IN', 'BOTH'], true)));
        $slots = $shifts === [] ? [] : $this->generator->generate($localDate, $localDate, $shifts, $in['hours'], $in['holidays'], $in['exceptions']);

        $hours = [];      // shift id => list of [start, end] of every slot (the effective hours)
        $windowSeconds = []; // shift id => walk-in window seconds
        $lastWindowEnd = null;
        foreach ($slots as $slot) {
            if ($slot->shiftId === null) {
                continue;
            }
            $hours[$slot->shiftId][] = [$slot->startsAt, $slot->endsAt];
            if ($slot->kind === 'WALK_IN_WINDOW') {
                $windowSeconds[$slot->shiftId] = ($windowSeconds[$slot->shiftId] ?? 0) + ($slot->endsAt - $slot->startsAt);
                $lastWindowEnd = max($lastWindowEnd ?? 0, $slot->endsAt);
            }
        }

        $shiftRows = $hours === [] ? collect() : DB::table('doctor_schedule_shifts')->whereIn('id', array_keys($hours))->get(['id', 'walkin_capacity', 'slot_duration_minutes', 'buffer_minutes'])->keyBy('id');
        $capacity = 0;
        $spans = [];
        $lastShiftEnd = null;
        foreach ($hours as $shiftId => $list) {
            $row = $shiftRows[$shiftId] ?? null;
            if ($row !== null) {
                $length = max(1, ((int) $row->slot_duration_minutes + (int) $row->buffer_minutes) * 60);
                $capacity += $row->walkin_capacity !== null ? (int) $row->walkin_capacity : intdiv($windowSeconds[$shiftId] ?? 0, $length);
            }
            $starts = array_column($list, 0);
            $ends = array_column($list, 1);
            $spans[] = [min($starts), max($ends)];
            $lastShiftEnd = max($lastShiftEnd ?? 0, max($ends));
        }
        $merged = Intervals::normalize($spans);
        $gaps = [];
        for ($i = 1; $i < count($merged); $i++) {
            $gaps[] = [$merged[$i - 1][1], $merged[$i][0]];
        }

        $breaks = [];
        foreach ($shifts as $shift) {
            if (! isset($hours[$shift->id])) {
                continue;
            }
            foreach ($shift->breaks as [$from, $to]) {
                $breaks[] = [$this->at($localDate, $from, $shift->timezone), $this->at($localDate, $to, $shift->timezone)];
            }
        }

        $now = $this->clock->now();
        $grace = $this->lateGrace($doctorId, $locationId, $graceFallbackMinutes);
        $windows = fn (array $list) => array_values(array_map(fn (array $i) => new TimeWindow(CarbonImmutable::createFromTimestampUTC($i[0]), CarbonImmutable::createFromTimestampUTC($i[1])), $list));
        $end = fn (?int $ts) => $ts === null ? null : CarbonImmutable::createFromTimestampUTC($ts);

        return new WalkInDayFacts(
            $timezone,
            $clinicClosed,
            $clinicCloses,
            $hours !== [],
            $capacity,
            $end($lastWindowEnd ?? $lastShiftEnd),
            $end($lastShiftEnd),
            $windows($merged),
            $windows($gaps),
            $windows(Intervals::normalize($breaks)),
            $this->appointments($doctorId, $dayStart, $dayEnd, $now, $grace),
            $this->holds($doctorId, $dayStart, $dayEnd, $now),
            $grace,
            DB::table('doctor_profiles')->where('id', $doctorId)->where('onboarding_status', 'ACTIVE')->exists(),
            $location !== null && DB::table('clinic_locations as l')->join('clinics as c', 'c.id', '=', 'l.clinic_id')->where('l.id', $locationId)->where('l.status', 'ACTIVE')->where('c.status', 'ACTIVE')->exists(),
        );
    }

    /**
     * Whether the location is closed that day and when it closes (UTC), from its opening hours and holidays.
     *
     * @return array{0: bool, 1: CarbonImmutable|null}
     */
    private function clinicHours(string $clinicId, string $locationId, string $localDate, int $dow, string $timezone): array
    {
        $hours = [$locationId => []];
        foreach (DB::table('clinic_operating_hours')->where('clinic_location_id', $locationId)->get() as $h) {
            $hours[$locationId][] = ['day' => (int) $h->day_of_week, 'opens' => (string) $h->opens_at, 'closes' => (string) $h->closes_at, 'from' => $h->effective_from, 'until' => $h->effective_until];
        }
        $holidays = [];
        foreach (DB::table('clinic_holidays')->where('clinic_id', $clinicId)->where('date', $localDate)->where(fn ($q) => $q->whereNull('clinic_location_id')->orWhere('clinic_location_id', $locationId))->get() as $h) {
            $holidays[] = new HolidayInput($locationId, (string) $h->date, (bool) $h->is_closed, $h->special_opens_at, $h->special_closes_at);
        }
        $open = $this->generator->openWindows($locationId, $localDate, $dow, $timezone, $hours, $holidays);

        return $open === [] ? [true, null] : [false, CarbonImmutable::createFromTimestampUTC((int) max(array_column($open, 1)))];
    }

    /** @return list<TimeWindow> */
    private function appointments(string $doctorId, CarbonImmutable $dayStart, CarbonImmutable $dayEnd, CarbonImmutable $now, int $grace): array
    {
        // Still protected while the visit lasts, and at least until the late grace after its start has passed (m2d).
        $rows = DB::table('appointments')->where('doctor_profile_id', $doctorId)->whereIn('status', self::LIVE_APPOINTMENT)
            ->where('starts_at', '<', $dayEnd)->where('ends_at', '>', $dayStart)
            ->whereRaw("GREATEST(ends_at, starts_at + (? * interval '1 minute')) > ?", [$grace, $now])->orderBy('starts_at')->get(['starts_at', 'ends_at']);

        return array_values($rows->map(fn ($r) => new TimeWindow(CarbonImmutable::parse((string) $r->starts_at)->utc(), CarbonImmutable::parse((string) $r->ends_at)->utc()))->all());
    }

    /** @return list<TimeWindow> */
    private function holds(string $doctorId, CarbonImmutable $dayStart, CarbonImmutable $dayEnd, CarbonImmutable $now): array
    {
        // An ACTIVE hold past its expiry no longer counts, even before the expiry job flips it (m2c).
        $rows = DB::table('appointment_slot_reservations')->where('doctor_profile_id', $doctorId)->where('status', 'ACTIVE')->where('expires_at', '>', $now)
            ->where('starts_at', '<', $dayEnd)->where('ends_at', '>', $dayStart)->where('ends_at', '>', $now)->orderBy('starts_at')->get(['starts_at', 'ends_at']);

        return array_values($rows->map(fn ($r) => new TimeWindow(CarbonImmutable::parse((string) $r->starts_at)->utc(), CarbonImmutable::parse((string) $r->ends_at)->utc()))->all());
    }

    /** The scheduling policy's late grace, else the caller's fallback (D-22's proposed value, not owner-approved). */
    private function lateGrace(string $doctorId, string $locationId, int $fallback): int
    {
        try {
            $grace = $this->policies->resolve($doctorId, $locationId)['late_grace_minutes'] ?? null;
        } catch (LogicException) {
            $grace = null; // no platform policy seeded: the fallback applies
        }

        return is_int($grace) ? $grace : $fallback;
    }

    private function at(string $date, string $time, string $timezone): int
    {
        return CarbonImmutable::parse($date.' '.substr($time, 0, 5), $timezone)->getTimestamp();
    }
}
