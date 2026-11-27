<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Presence\DTOs\AdmissionPresence;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Scheduling\DTOs\TimeWindow;
use App\Modules\Scheduling\DTOs\WalkInDayFacts;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\PriorityLevel;
use App\Modules\WalkIns\Estimation\BlockedInterval;
use App\Modules\WalkIns\Estimation\BlockKind;
use App\Modules\WalkIns\Estimation\CurrentConsultationInput;
use App\Modules\WalkIns\Estimation\EstimateInputs;
use App\Modules\WalkIns\Estimation\ExpectedDuration;
use App\Modules\WalkIns\Estimation\PresenceInput;
use App\Modules\WalkIns\Estimation\QueueEntryInput;
use App\Modules\WalkIns\Estimation\RollingDurationStats;
use App\Modules\WalkIns\Estimation\WaitEstimate;
use App\Modules\WalkIns\Estimation\WaitTimeEstimator;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds the wait estimator's inputs from real data (increment 5.5a, walk-in-management.md §3.5) and runs it. The estimator
 * stays pure; this class is the only place that knows where each input comes from. An estimate is advisory: it is
 * computed on every read and never stored for clients (decision 98).
 *
 * Inputs: arrival time (sortAt) from queue_sort_at; closing as the earlier of the clinic's closing and the doctor's last shift's end;
 * gaps between shifts (OFF_SHIFT) and scheduled breaks; live appointments, protected until the late grace has passed (m2d), and ACTIVE
 * unexpired holds; the presence return instant; the consultation running now from any source (always none until 5.6 and Phase 6).
 */
final class QueueEstimates
{
    /** @var array<string, ExpectedDuration> */
    private array $durations = [];

    public function __construct(
        private readonly WaitTimeEstimator $estimator,
        private readonly RollingDurationStats $stats,
        private readonly Clock $clock,
    ) {}

    /** @param iterable<WalkInQueueEntry> $entries the queue's entries; only REGISTERED, WAITING and CALLED count as ahead */
    public function inputs(string $doctorId, string $localDate, WalkInDayFacts $facts, AdmissionPresence $presence, iterable $entries): EstimateInputs
    {
        $inputs = [];
        foreach ($entries as $entry) {
            if (! in_array($entry->status, [EntryStatus::Registered, EntryStatus::Waiting, EntryStatus::Called], true)) {
                continue;
            }
            $duration = $this->duration($doctorId, $entry->doctor_service_id);
            $arrived = $entry->status === EntryStatus::Registered ? null : ($entry->queue_sort_at ?? $entry->arrived_at ?? $entry->called_at ?? $this->clock->now());
            $inputs[] = new QueueEntryInput($entry->id, $entry->queue_number, $entry->triage_priority->value, $entry->status, $duration->seconds, $duration->source, $arrived);
        }

        return new EstimateInputs($inputs, $this->current($doctorId), $this->blocked($facts), $this->presenceInput($presence, $facts), $facts->closesAt(), $facts->timezone, $localDate);
    }

    public function duration(string $doctorId, ?string $doctorServiceId): ExpectedDuration
    {
        $key = $doctorId.':'.($doctorServiceId ?? '-');

        return $this->durations[$key] ??= $this->stats->expectedFor($doctorId, $doctorServiceId);
    }

    /**
     * When a patient called now would actually be seen (D8): now, or the expected end of the consultation in progress, whichever is later.
     * Expected, not promised: an overrunning consultation ends later than this.
     */
    public function callWindowStart(string $doctorId): CarbonImmutable
    {
        $now = $this->clock->now();
        $current = $this->current($doctorId);
        if ($current === null) {
            return $now;
        }
        $end = $current->startedAt->addSeconds($current->expectedDurationSeconds);

        return $end > $now ? $end : $now;
    }

    /**
     * The protected interval a call for [$start, $start + $seconds) would overlap (D8): a live appointment (protected until its end, and at
     * least until the late grace has passed) or an ACTIVE hold. Scheduled breaks and off-shift gaps do not refuse a call. Null when clear.
     * Adjacent and overlapping protected windows are merged, so `until` is when the whole protected run ends, not just the first window.
     *
     * @return array{reason: string, until: CarbonImmutable}|null `reason` is `appointment` or `hold` (of the first window hit)
     */
    public function protectedConflict(WalkInDayFacts $facts, CarbonImmutable $start, int $seconds): ?array
    {
        $end = $start->addSeconds(max(1, $seconds));
        $grace = $facts->lateGraceMinutes ?? (int) config('walkins.estimation.appointment_grace_minutes', 15);
        $windows = [];
        foreach ($facts->appointments as $w) {
            $windows[] = ['reason' => 'appointment', 'from' => $w->startsAt, 'until' => $this->appointmentProtectedUntil($w, $grace)];
        }
        foreach ($facts->activeHolds as $w) {
            $windows[] = ['reason' => 'hold', 'from' => $w->startsAt, 'until' => $w->endsAt];
        }
        usort($windows, fn (array $a, array $b) => $a['from'] <=> $b['from']);

        $first = null;
        foreach ($windows as $i => $w) {
            if ($start < $w['until'] && $w['from'] < $end) {
                $first = $i;
                break;
            }
        }
        if ($first === null) {
            return null;
        }
        $until = $windows[$first]['until'];
        foreach (array_slice($windows, $first + 1) as $w) {
            if ($w['from'] <= $until && $w['until'] > $until) {
                $until = $w['until'];
            }
        }

        return ['reason' => $windows[$first]['reason'], 'until' => $until];
    }

    /** A visit is protected for the whole appointment, and at least until the late grace has passed (m2d). */
    private function appointmentProtectedUntil(TimeWindow $w, int $graceMinutes): CarbonImmutable
    {
        $graceEnd = $w->startsAt->addMinutes($graceMinutes);

        return $graceEnd > $w->endsAt ? $graceEnd : $w->endsAt;
    }

    /** The estimate for a patient who is about to join: staff-registered entries are WAITING since now, remote ones REGISTERED. */
    public function forNewEntry(EstimateInputs $inputs, ExpectedDuration $duration, EntryStatus $status, PriorityLevel $priority): WaitEstimate
    {
        return $this->estimator->estimateForNewEntry($inputs, $duration->seconds, $duration->source, $status, $status === EntryStatus::Waiting ? $this->clock->now() : null, $priority->value);
    }

    /** @return array<string, WaitEstimate> by entry id, in queue order */
    public function forEntries(EstimateInputs $inputs): array
    {
        return $this->estimator->estimate($inputs);
    }

    private function presenceInput(AdmissionPresence $presence, WalkInDayFacts $facts): PresenceInput
    {
        return match ($presence->status) {
            PresenceStatus::OnBreak => new PresenceInput($presence->status, $presence->breakEndsAt),
            // The presence API carries no announced return time, so an unavailable doctor's return is unknown (decision 179).
            PresenceStatus::TemporarilyUnavailable => new PresenceInput($presence->status, null),
            // Not in yet partway through a shift means a late doctor with no word: unknown, never "back at the next shift".
            PresenceStatus::NotCheckedIn, PresenceStatus::Out => new PresenceInput($presence->status, $facts->inShiftAt($this->clock->now()) ? null : $facts->nextShiftStartAfter($this->clock->now())),
            default => new PresenceInput($presence->status),
        };
    }

    /** @return list<BlockedInterval> */
    private function blocked(WalkInDayFacts $facts): array
    {
        $now = $this->clock->now();
        $grace = $facts->lateGraceMinutes ?? (int) config('walkins.estimation.appointment_grace_minutes', 15);
        $out = [];
        $add = function (BlockKind $kind, TimeWindow $w, ?CarbonImmutable $end = null) use (&$out, $now): void {
            $endsAt = $end ?? $w->endsAt;
            if ($endsAt > $now && $endsAt > $w->startsAt) {
                $out[] = new BlockedInterval($kind, $w->startsAt, $endsAt);
            }
        };
        foreach ($facts->offShiftGaps as $w) {
            $add(BlockKind::OffShift, $w);
        }
        foreach ($facts->scheduledBreaks as $w) {
            $add(BlockKind::ScheduledBreak, $w);
        }
        foreach ($facts->appointments as $w) {
            // An online appointment blocks too (m2e).
            $add(BlockKind::Appointment, $w, $this->appointmentProtectedUntil($w, $grace));
        }
        foreach ($facts->activeHolds as $w) {
            $add(BlockKind::Hold, $w);
        }

        return $out;
    }

    /** The consultation in progress for the doctor, from any source (m2a). */
    private function current(string $doctorId): ?CurrentConsultationInput
    {
        $consultation = Consultation::query()->where('doctor_profile_id', $doctorId)->where('status', ConsultationStatus::InProgress)->whereNotNull('started_at')->first();
        if ($consultation === null || $consultation->started_at === null) {
            return null;
        }
        $serviceId = $consultation->appointment_id !== null
            ? DB::table('appointments')->where('id', $consultation->appointment_id)->value('doctor_service_id')
            : WalkInQueueEntry::query()->whereKey($consultation->walkin_queue_entry_id)->value('doctor_service_id');
        $duration = $this->duration($doctorId, is_string($serviceId) ? $serviceId : null);

        return new CurrentConsultationInput($consultation->started_at, $duration->seconds, $duration->source);
    }
}
