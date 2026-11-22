<?php

namespace App\Modules\WalkIns\Estimation;

use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Scheduling\Slots\Intervals;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;

/**
 * The pure walk-in wait-time estimator (increment 5.4, architecture.md §10.4, CLAUDE.md §9.4).
 * No database, no config reads: the same inputs and the same clock always give the same estimates,
 * so it can be tested exhaustively. Gathering the inputs and storing the result is 5.5.
 *
 * It replays the doctor's day from "now". Time the doctor cannot give a walk-in is a set of blocked
 * intervals: protected appointments and holds, scheduled breaks, gaps between shifts and, when the doctor
 * is on a break, unavailable or not yet checked in, the time until they are back. Blocked intervals are
 * merged first, so a break that overlaps an appointment is counted once. The queue is served in
 * call-next order (called first, then highest triage priority, then lowest number); each consultation
 * starts at the earliest moment it fits entirely before the next blocked interval, mirroring the
 * call-next refusal in 5.6 (APPOINTMENT_WINDOW_PROTECTED).
 *
 * Two kinds of uncertainty. A consultation's length is uncertain: the replay runs twice, with every
 * duration shortened and lengthened by the spread. A fixed return time (break end, announced return,
 * shift start) is the earliest the doctor can be back and is never counted earlier; the high end allows
 * lateness (spread of the time away, between the minimum width and the configured cap).
 * No estimate is ever a single number: a zero wait still spans the minimum width.
 */
final class WaitTimeEstimator
{
    private const NEW_ENTRY_ID = 'new-entry';

    public function __construct(
        private readonly Clock $clock,
        private readonly EstimatorSettings $settings = new EstimatorSettings,
    ) {}

    /**
     * Estimates for every queue entry still waiting (REGISTERED, WAITING, CALLED), in queue order.
     *
     * @return array<string, WaitEstimate> by entry id
     */
    public function estimate(EstimateInputs $inputs): array
    {
        $ordered = $this->order($inputs->entries);
        if ($ordered === []) {
            return [];
        }

        $now = $this->clock->now()->getTimestamp();
        $context = $this->context($inputs, $now);

        $lo = $context['unknown'] ? null : $this->replay($ordered, $inputs, $context, $now, 1 - $this->spread());
        $hi = $context['unknown'] ? null : $this->replay($ordered, $inputs, $context, $now, 1 + $this->spread());

        $out = [];
        foreach ($ordered as $i => $entry) {
            $out[$entry->id] = $this->build($ordered, $i, $inputs, $context, $now, $lo[$i] ?? null, $hi[$i] ?? null);
        }

        return $out;
    }

    /** The estimate a patient would see if they joined the queue now with the given triage priority (0 = ordinary). */
    public function estimateForNewEntry(EstimateInputs $inputs, int $expectedDurationSeconds, DurationSource $source, int $triagePriority = 0): WaitEstimate
    {
        $candidate = new QueueEntryInput(self::NEW_ENTRY_ID, PHP_INT_MAX, $triagePriority, EntryStatus::Waiting, $expectedDurationSeconds, $source);
        $withCandidate = new EstimateInputs([...$inputs->entries, $candidate], $inputs->current, $inputs->blockedIntervals, $inputs->presence, $inputs->closesAt, $inputs->timezone, $inputs->localDate);

        return $this->estimate($withCandidate)[self::NEW_ENTRY_ID];
    }

    private function spread(): float
    {
        return $this->settings->spreadPercent / 100;
    }

    /**
     * Call-next order. A called patient is already on the way in; then triage, then arrival order.
     *
     * @param  list<QueueEntryInput>  $entries
     * @return list<QueueEntryInput>
     */
    private function order(array $entries): array
    {
        $ahead = array_values(array_filter($entries, fn (QueueEntryInput $e) => $e->isAhead()));
        usort($ahead, fn (QueueEntryInput $a, QueueEntryInput $b) => [$a->status === EntryStatus::Called ? 0 : 1, -$a->triagePriority, $a->queueNumber, $a->id]
            <=> [$b->status === EntryStatus::Called ? 0 : 1, -$b->triagePriority, $b->queueNumber, $b->id]);

        return $ahead;
    }

    /**
     * What the doctor's state means for the replay, resolved once.
     *
     * @return array{unknown: bool, basis: list<WaitBasis>, resumeAt: int|null, resumeOverrun: bool, currentOverrun: bool}
     */
    private function context(EstimateInputs $inputs, int $now): array
    {
        $presence = $inputs->presence;
        $basis = [];
        $unknown = false;
        $resumeAt = null;
        $resumeOverrun = false;

        $resumes = $presence->resumesAt?->getTimestamp();
        switch ($presence->status) {
            case PresenceStatus::In:
            case PresenceStatus::Busy:
                break;
            case PresenceStatus::OnBreak:
            case PresenceStatus::TemporarilyUnavailable:
                $basis[] = $presence->status === PresenceStatus::OnBreak ? WaitBasis::DoctorBreak : WaitBasis::DoctorUnavailable;
                if ($resumes === null) {
                    $unknown = true;
                } elseif ($resumes <= $now) {
                    // Past the expected end and still away: the real end is unknown, so allow a margin.
                    $resumeOverrun = true;
                    $resumeAt = $now;
                    $basis[] = WaitBasis::BreakOverrun;
                } else {
                    $resumeAt = $resumes;
                }
                break;
            case PresenceStatus::NotCheckedIn:
            case PresenceStatus::Out:
                $basis[] = $presence->status === PresenceStatus::Out ? WaitBasis::DoctorCheckedOut : WaitBasis::DoctorNotCheckedIn;
                // A shift start in the past means a late doctor with no word: that is unknown, not "now".
                if ($resumes === null || $resumes <= $now) {
                    $unknown = true;
                } else {
                    $resumeAt = $resumes;
                }
                break;
        }
        if ($unknown) {
            $basis[] = WaitBasis::Unknown;
        }

        return [
            'unknown' => $unknown,
            'basis' => $basis,
            'resumeAt' => $resumeAt,
            'resumeOverrun' => $resumeOverrun,
            'currentOverrun' => $inputs->current !== null && $inputs->current->startedAt->getTimestamp() + $inputs->current->expectedDurationSeconds <= $now,
        ];
    }

    /**
     * One pass over the queue with every uncertain duration scaled by $factor.
     *
     * @param  list<QueueEntryInput>  $ordered
     * @param  array{unknown: bool, basis: list<WaitBasis>, resumeAt: int|null, resumeOverrun: bool, currentOverrun: bool}  $context
     * @return list<int> the second each entry's consultation would start, by queue position
     */
    private function replay(array $ordered, EstimateInputs $inputs, array $context, int $now, float $factor): array
    {
        $blocked = [];
        foreach ($inputs->blockedIntervals as $b) {
            if ($b->endsAt->getTimestamp() > $now) {
                $blocked[] = [$b->startsAt->getTimestamp(), $b->endsAt->getTimestamp()];
            }
        }

        if ($context['resumeAt'] !== null) {
            // The return time is a fixed instant: the low case is exactly then, the high case allows lateness.
            $away = $context['resumeAt'] - $now;
            $blocked[] = [$now, $factor > 1 ? $context['resumeAt'] + $this->lateness($away) : $context['resumeAt']];
        }
        // One merged set: a break that overlaps an appointment is not counted twice.
        $blocked = Intervals::normalize($blocked);

        $cursor = $now;
        if ($inputs->current !== null) {
            $left = $inputs->current->startedAt->getTimestamp() + $inputs->current->expectedDurationSeconds - $now;
            $cursor += $left > 0
                ? (int) round($left * $factor)
                // Overrunning: it may end any moment (low) or take a while longer (high); never negative.
                : ($factor > 1 ? (int) round($inputs->current->expectedDurationSeconds * $this->spread()) : 0);
        }

        $starts = [];
        foreach ($ordered as $entry) {
            $length = max(1, (int) round($entry->expectedDurationSeconds * $factor));
            $start = $cursor;
            foreach ($blocked as [$from, $to]) {
                if ($from < $start + $length && $to > $start) {
                    $start = $to;
                }
            }
            $starts[] = $start;
            $cursor = $start + $length;
        }

        return $starts;
    }

    /** How late a doctor with a fixed return time may be: spread of the time away, within the configured bounds. */
    private function lateness(int $away): int
    {
        return max($this->settings->minRangeWidthMinutes * 60, min($this->settings->maxLatenessMinutes * 60, (int) round($away * $this->spread())));
    }

    /**
     * @param  list<QueueEntryInput>  $ordered
     * @param  array{unknown: bool, basis: list<WaitBasis>, resumeAt: int|null, resumeOverrun: bool, currentOverrun: bool}  $context
     */
    private function build(array $ordered, int $i, EstimateInputs $inputs, array $context, int $now, ?int $startLo, ?int $startHi): WaitEstimate
    {
        $entry = $ordered[$i];
        $basis = $context['basis'];
        $closes = $inputs->closesAt?->getTimestamp();

        if ($startLo === null || $startHi === null) {
            // Cannot be estimated. A closed clinic (or a day already over) still settles the "today" question.
            $over = $closes !== null ? $now >= $closes : ($this->localDate($now, $inputs->timezone) !== $inputs->localDate ? true : null);
            if ($over === true) {
                $basis[] = WaitBasis::ClinicClosing;
            }

            return new WaitEstimate($entry->id, $i + 1, null, null, $over === true ? true : null, $this->unique($basis), null, null);
        }

        $minSeconds = max(0, $startLo - $now);
        $maxSeconds = max($minSeconds, $startHi - $now);
        $min = intdiv($minSeconds, 60);
        $max = (int) ceil($maxSeconds / 60);
        if ($max - $min < $this->settings->minRangeWidthMinutes) {
            // Never one number, not even for a patient who is next in line.
            $max = $min + $this->settings->minRangeWidthMinutes;
        }
        $callMax = $now + $max * 60;

        $basis = [...$basis, ...$this->queueBasis($ordered, $i, $inputs, $context, $now, $startHi)];
        if ($minSeconds === 0 && $maxSeconds === 0) {
            $basis[] = WaitBasis::NextInLine;
        }
        // The clinic's (or the doctor's last shift's) end decides when it is known; the calendar day only when it is not.
        $mayNot = $closes !== null
            ? $now >= $closes || $callMax >= $closes
            : $this->localDate($callMax, $inputs->timezone) !== $inputs->localDate;
        if ($mayNot) {
            $basis[] = WaitBasis::ClinicClosing;
        }

        return new WaitEstimate(
            $entry->id,
            $i + 1,
            $min,
            $max,
            $mayNot,
            $this->unique($basis),
            CarbonImmutable::createFromTimestampUTC($now + $min * 60),
            CarbonImmutable::createFromTimestampUTC($callMax),
        );
    }

    /**
     * @param  list<QueueEntryInput>  $ordered
     * @param  array{unknown: bool, basis: list<WaitBasis>, resumeAt: int|null, resumeOverrun: bool, currentOverrun: bool}  $context
     * @return list<WaitBasis>
     */
    private function queueBasis(array $ordered, int $i, EstimateInputs $inputs, array $context, int $now, int $startHi): array
    {
        $entry = $ordered[$i];
        $basis = [];

        if ($inputs->current !== null) {
            $basis[] = $context['currentOverrun'] ? WaitBasis::ConsultationOverrun : WaitBasis::CurrentConsultation;
            $basis[] = $this->durationBasis($inputs->current->durationSource);
        }
        if ($i > 0) {
            $basis[] = WaitBasis::QueueAhead;
        }
        foreach (array_slice($ordered, 0, $i + 1) as $j => $other) {
            $basis[] = $this->durationBasis($other->durationSource);
            if ($j < $i && $other->status === EntryStatus::Registered) {
                $basis[] = WaitBasis::UnarrivedCounted;
            }
        }
        foreach ($inputs->blockedIntervals as $b) {
            // Anything that lies between now and this patient's turn (even the end of a break or appointment already under way).
            if ($b->endsAt->getTimestamp() > $now && $b->startsAt->getTimestamp() < $startHi) {
                $basis[] = $b->kind->basis();
            }
        }
        foreach ($ordered as $j => $other) {
            // Someone ahead who joined later but outranks this patient, or this patient outranks someone who joined earlier.
            if (($j < $i && $other->triagePriority > $entry->triagePriority && $other->queueNumber > $entry->queueNumber)
                || ($j > $i && $entry->triagePriority > $other->triagePriority && $other->queueNumber < $entry->queueNumber)) {
                $basis[] = WaitBasis::TriageReordered;
                break;
            }
        }

        return $basis;
    }

    private function durationBasis(DurationSource $source): WaitBasis
    {
        return match ($source) {
            DurationSource::Rolling => WaitBasis::DurationRolling,
            DurationSource::Service => WaitBasis::DurationService,
            DurationSource::Default => WaitBasis::DurationDefault,
        };
    }

    /**
     * Distinct codes in a fixed order, so the output is deterministic.
     *
     * @param  list<WaitBasis>  $basis
     * @return list<WaitBasis>
     */
    private function unique(array $basis): array
    {
        return array_values(array_filter(WaitBasis::cases(), fn (WaitBasis $c) => in_array($c, $basis, true)));
    }

    private function localDate(int $timestamp, string $timezone): string
    {
        return CarbonImmutable::createFromTimestampUTC($timestamp)->setTimezone($timezone)->toDateString();
    }
}
