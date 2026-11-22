<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Scheduling\Exceptions\SlotUnavailable;
use App\Modules\Scheduling\Slots\AvailabilityInputs;
use App\Modules\Scheduling\Slots\GeneratedSlot;
use App\Modules\Scheduling\Slots\SlotGenerator;
use App\Modules\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Temporary holds on a slot (increment 4.7, architecture.md §8.3). Defence in
 * depth, strongest last:
 *   1. a short Redis lock sheds contention (never relied on for correctness, §25);
 *   2. the slot row is locked FOR UPDATE inside the transaction;
 *   3. the slot is revalidated against PostgreSQL: still generated from the
 *      current schedule, policy and exceptions (catches a stale projection);
 *   4. the partial unique index allows one ACTIVE hold per slot;
 *   5. the exclusion constraint stops overlapping ACTIVE holds of one doctor
 *      across different slots (concurrency scenario 11).
 * A violation of 4 or 5 answers 409 SLOT_UNAVAILABLE.
 */
final class SlotHolds
{
    public const MAX_LIVE_HOLDS_PER_PATIENT = 2;

    public function __construct(
        private readonly SchedulingPolicies $policies,
        private readonly AvailabilityInputs $inputs,
        private readonly SlotGenerator $generator,
        private readonly RecordsAudit $audit,
    ) {}

    /** @return array{id: string, slot_id: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, expires_at: CarbonImmutable} */
    public function hold(string $slotId, string $patientProfileId, User $actor, ?string $idempotencyKey = null): array
    {
        $slot = DB::table('doctor_availability_slots')->where('id', $slotId)->first(['doctor_profile_id']) ?? throw new SlotUnavailable;
        $lock = Cache::lock("appointment:lock:{$slot->doctor_profile_id}:{$slotId}", 10);
        if (! $lock->get()) {
            $this->rejected('busy');
            throw new SlotUnavailable('Someone is reserving this time right now. Please choose another or try again.');
        }

        try {
            $result = DB::transaction(function () use ($slotId, $patientProfileId, $actor, $idempotencyKey) {
                DB::statement("SET LOCAL lock_timeout = '".(int) config('scheduling.hold_lock_timeout_ms', 3000)."ms'");
                $slot = DB::table('doctor_availability_slots')->where('id', $slotId)->lockForUpdate()->first() ?? throw new SlotUnavailable;
                $now = CarbonImmutable::now();

                // A hold past its expiry no longer counts, even if the expiry job has not run yet: on this
                // slot, and on any overlapping slot of the same doctor (the exclusion constraint would see it).
                DB::table('appointment_slot_reservations')->where('doctor_profile_id', $slot->doctor_profile_id)->where('status', 'ACTIVE')
                    ->where('expires_at', '<=', $now)->where('starts_at', '<', $slot->ends_at)->where('ends_at', '>', $slot->starts_at)
                    ->update(['status' => 'EXPIRED', 'updated_at' => $now]);

                $this->revalidate($slot, $now);

                $live = DB::table('appointment_slot_reservations')->where('patient_profile_id', $patientProfileId)->where('status', 'ACTIVE')->where('expires_at', '>', $now)->count();
                if ($live >= self::MAX_LIVE_HOLDS_PER_PATIENT) {
                    throw ValidationException::withMessages(['slot_id' => ['You already have '.self::MAX_LIVE_HOLDS_PER_PATIENT.' times on hold. Release one or let it expire first.']]);
                }

                $policy = $this->policies->resolve((string) $slot->doctor_profile_id, $slot->clinic_location_id, (string) $slot->doctor_service_mode_id);
                $id = (string) Str::uuid7();
                $expires = $now->addMinutes((int) $policy['reservation_hold_minutes']);
                DB::table('appointment_slot_reservations')->insert([
                    'id' => $id, 'doctor_availability_slot_id' => $slotId, 'doctor_profile_id' => $slot->doctor_profile_id,
                    'starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at, 'patient_profile_id' => $patientProfileId,
                    'booked_by_user_id' => $actor->id, 'status' => 'ACTIVE', 'expires_at' => $expires, 'idempotency_key' => $idempotencyKey,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('doctor_availability_slots')->where('id', $slotId)->update(['status' => 'HELD', 'updated_at' => $now]);
                $this->audit->record('scheduling.slot_held', 'appointment_slot_reservation', $id, ['slot_id' => $slotId]);

                return ['id' => $id, 'slot_id' => $slotId, 'doctor_id' => (string) $slot->doctor_profile_id, 'starts_at' => CarbonImmutable::parse((string) $slot->starts_at), 'ends_at' => CarbonImmutable::parse((string) $slot->ends_at), 'expires_at' => $expires];
            });
        } catch (QueryException $e) {
            // 23505: unique (one active hold per slot); 23P01: exclusion (overlapping hold); 55P03: lock timeout.
            if (in_array($e->getCode(), ['23505', '23P01', '55P03'], true)) {
                $this->rejected((string) $e->getCode());
                throw new SlotUnavailable;
            }
            throw $e;
        } finally {
            $lock->release();
        }

        AvailabilityCache::bump($result['doctor_id']);
        unset($result['doctor_id']);

        return $result;
    }

    /** Releases the caller's own hold early. */
    public function release(string $reservationId, User $actor): void
    {
        $doctor = DB::transaction(function () use ($reservationId, $actor) {
            $hold = DB::table('appointment_slot_reservations')->where('id', $reservationId)->where('booked_by_user_id', $actor->id)->lockForUpdate()->first();
            abort_if($hold === null, 404, 'Hold not found.');
            if ($hold->status !== 'ACTIVE') {
                return null;
            }
            DB::table('appointment_slot_reservations')->where('id', $reservationId)->update(['status' => 'RELEASED', 'updated_at' => now()]);
            self::reopen([(string) $hold->doctor_availability_slot_id]);
            $this->audit->record('scheduling.hold_released', 'appointment_slot_reservation', $reservationId);

            return (string) $hold->doctor_profile_id;
        });
        if ($doctor !== null) {
            AvailabilityCache::bump($doctor);
        }
    }

    /** ExpireSlotReservations: ACTIVE → EXPIRED once past expiry; their slots reopen. Returns how many expired. */
    public function expireDue(): int
    {
        return DB::transaction(function () {
            $due = DB::table('appointment_slot_reservations')->where('status', 'ACTIVE')->where('expires_at', '<=', now())->lockForUpdate()->limit(1000)->get(['id', 'doctor_availability_slot_id', 'doctor_profile_id']);
            if ($due->isEmpty()) {
                return 0;
            }
            DB::table('appointment_slot_reservations')->whereIn('id', $due->pluck('id'))->update(['status' => 'EXPIRED', 'updated_at' => now()]);
            $slots = [];
            foreach ($due as $row) {
                if ($row->doctor_availability_slot_id !== null) {
                    $slots[] = (string) $row->doctor_availability_slot_id;
                }
            }
            self::reopen($slots);
            foreach ($due->pluck('doctor_profile_id')->unique() as $doctor) {
                DB::afterCommit(fn () => AvailabilityCache::bump((string) $doctor));
            }

            return $due->count();
        });
    }

    /** @param list<string> $slotIds slots back to OPEN when nothing holds or books them any more */
    private static function reopen(array $slotIds): void
    {
        DB::table('doctor_availability_slots')->whereIn('id', $slotIds)->where('status', 'HELD')
            ->whereNotExists(fn ($q) => $q->from('appointment_slot_reservations')->whereColumn('doctor_availability_slot_id', 'doctor_availability_slots.id')->where('status', 'ACTIVE'))
            ->update(['status' => 'OPEN', 'updated_at' => now()]);
    }

    /** Layer 3: the slot must still exist under current PostgreSQL state, not just in the projection. */
    private function revalidate(\stdClass $slot, CarbonImmutable $now): void
    {
        if ($slot->slot_kind !== 'APPOINTMENT' || ! in_array($slot->status, ['OPEN', 'HELD'], true)) {
            $this->rejected('status');
            throw new SlotUnavailable;
        }
        $starts = CarbonImmutable::parse((string) $slot->starts_at);
        $policy = $this->policies->resolve((string) $slot->doctor_profile_id, $slot->clinic_location_id, (string) $slot->doctor_service_mode_id);
        $today = CarbonImmutable::now((string) config('mediconnect.timezone'))->toDateString();
        if ($starts->lt($now->addMinutes((int) $policy['min_booking_notice_minutes'])) || $starts->gt($now->addDays((int) $policy['max_advance_booking_days']))
            || (! $policy['allow_same_day'] && (string) $slot->local_date === $today)) {
            $this->rejected('policy');
            throw new SlotUnavailable('This time can no longer be reserved. Please choose a later time.');
        }
        $date = (string) $slot->local_date;
        $in = $this->inputs->load((string) $slot->doctor_profile_id, $date, $date);
        $current = array_filter($this->generator->generate($date, $date, $in['shifts'], $in['hours'], $in['holidays'], $in['exceptions']),
            fn (GeneratedSlot $g) => $g->kind === 'APPOINTMENT' && $g->modeId === $slot->doctor_service_mode_id && $g->startsAt === $starts->getTimestamp());
        if ($current === []) {
            $this->rejected('stale');
            throw new SlotUnavailable('This time was just changed by the clinic. Please choose another.');
        }
    }

    private function rejected(string $reason): void
    {
        // DOUBLE_BOOKING_REJECTIONS (§29): counted from logs; no patient data.
        Log::info('Slot hold rejected', ['metric' => 'DOUBLE_BOOKING_REJECTIONS', 'reason' => $reason]);
    }
}
