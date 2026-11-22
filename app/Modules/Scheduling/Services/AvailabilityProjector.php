<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Events\AvailabilityUpdated;
use App\Modules\Scheduling\Slots\AvailabilityInputs;
use App\Modules\Scheduling\Slots\GeneratedSlot;
use App\Modules\Scheduling\Slots\SlotGenerator;
use App\Shared\Services\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes one doctor's availability projection (increment 4.6, architecture.md
 * §8.2). Serialized per doctor with a transaction-scoped advisory lock, so
 * refreshes are idempotent and order-independent: each one rebuilds from
 * current PostgreSQL state. A slot that disappears while an appointment or
 * hold is live is kept and flagged NEEDS_REVIEW; live appointments on it get a
 * rescheduling task. Nothing is ever silently cancelled (§8.3).
 */
final class AvailabilityProjector
{
    private const LIVE_APPOINTMENT = ['PENDING_PAYMENT', 'PENDING_CONFIRMATION', 'CONFIRMED', 'CHECKED_IN'];

    public function __construct(private readonly AvailabilityInputs $inputs, private readonly SlotGenerator $generator, private readonly SchedulingPolicies $policies, private readonly Outbox $outbox) {}

    /** @return array{generated: int, removed: int, flagged: int} */
    public function refresh(string $doctorId, ?string $from = null, ?string $to = null): array
    {
        $tz = (string) config('mediconnect.timezone');
        $from ??= CarbonImmutable::now($tz)->toDateString();
        $to ??= CarbonImmutable::parse($from)->addDays((int) $this->policies->resolve($doctorId)['availability_horizon_days'] - 1)->toDateString();

        $result = DB::transaction(function () use ($doctorId, $from, $to) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 7))', ['availability:'.$doctorId]);
            $in = $this->inputs->load($doctorId, $from, $to);
            $slots = $this->generator->generate($from, $to, $in['shifts'], $in['hours'], $in['holidays'], $in['exceptions']);
            $generation = (int) (DB::table('doctor_availability_slots')->where('doctor_profile_id', $doctorId)->max('generation') ?? 0) + 1;
            $now = now();

            foreach (array_chunk($slots, 500) as $chunk) {
                DB::table('doctor_availability_slots')->upsert(array_map(fn (GeneratedSlot $s) => [
                    'id' => (string) Str::uuid7(), 'doctor_profile_id' => $doctorId, 'clinic_location_id' => $s->locationId, 'doctor_service_mode_id' => $s->modeId,
                    'doctor_schedule_shift_id' => $s->shiftId, 'slot_kind' => $s->kind, 'starts_at' => CarbonImmutable::createFromTimestamp($s->startsAt), 'ends_at' => CarbonImmutable::createFromTimestamp($s->endsAt),
                    'local_date' => $s->localDate, 'status' => 'OPEN', 'generation' => $generation, 'updated_at' => $now,
                ], $chunk), ['doctor_profile_id', 'starts_at', 'doctor_service_mode_id'], ['clinic_location_id', 'doctor_schedule_shift_id', 'slot_kind', 'ends_at', 'local_date', 'generation', 'updated_at']);
            }

            $inRange = fn () => DB::table('doctor_availability_slots')->where('doctor_profile_id', $doctorId)->whereBetween('local_date', [$from, $to]);
            $liveAppointment = fn ($q) => $q->from('appointments')->whereColumn('appointments.doctor_availability_slot_id', 'doctor_availability_slots.id')->whereIn('appointments.status', self::LIVE_APPOINTMENT);
            $activeHold = fn ($q) => $q->from('appointment_slot_reservations')->whereColumn('appointment_slot_reservations.doctor_availability_slot_id', 'doctor_availability_slots.id')->where('appointment_slot_reservations.status', 'ACTIVE');

            // Disappeared slots: keep and flag those in use; delete the rest.
            $stale = $inRange()->where('generation', '<>', $generation);
            $flaggedSlots = (clone $stale)->where(fn ($q) => $q->whereExists($liveAppointment)->orWhereExists($activeHold))->pluck('id');
            DB::table('doctor_availability_slots')->whereIn('id', $flaggedSlots)->update(['status' => 'NEEDS_REVIEW', 'updated_at' => $now]);
            $removed = (clone $stale)->whereNotIn('id', $flaggedSlots)->delete();
            $this->flagAppointments(array_values(array_map('strval', $flaggedSlots->all())));

            // Current slots: status follows the facts (the constraints stay the authority).
            $inRange()->where('generation', $generation)->whereExists($liveAppointment)->update(['status' => 'BOOKED']);
            $inRange()->where('generation', $generation)->whereNotExists($liveAppointment)->whereExists($activeHold)->update(['status' => 'HELD']);
            $inRange()->where('generation', $generation)->whereNotExists($liveAppointment)->whereNotExists($activeHold)->where('status', '<>', 'OPEN')->update(['status' => 'OPEN']);

            // Search re-indexes from the committed projection (4.8); the bump runs first so nothing reads a stale cache.
            DB::afterCommit(fn () => AvailabilityCache::bump($doctorId));
            $this->outbox->record(new AvailabilityUpdated($doctorId));

            return ['generated' => count($slots), 'removed' => $removed, 'flagged' => $flaggedSlots->count()];
        });

        return $result;
    }

    /** @param list<string> $slotIds */
    private function flagAppointments(array $slotIds): void
    {
        if ($slotIds === []) {
            return;
        }
        $ids = DB::table('appointments')->whereIn('doctor_availability_slot_id', $slotIds)->whereIn('status', self::LIVE_APPOINTMENT)->pluck('id');
        DB::table('appointments')->whereIn('id', $ids)->update(['needs_rescheduling' => true, 'updated_at' => now()]);
        foreach ($ids as $id) {
            DB::table('rescheduling_tasks')->insertOrIgnore(['id' => (string) Str::uuid7(), 'appointment_id' => $id, 'reason' => 'SCHEDULE_CHANGE', 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
