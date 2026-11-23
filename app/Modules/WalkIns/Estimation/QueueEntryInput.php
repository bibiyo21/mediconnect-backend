<?php

namespace App\Modules\WalkIns\Estimation;

use App\Modules\WalkIns\Enums\EntryStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** One entry of the queue as the estimator sees it. Immutable. */
final class QueueEntryInput
{
    /** No consultation is expected to take more than a day; larger values are bad data. */
    public const MAX_SECONDS = 86400;

    public function __construct(
        public readonly string $id,
        public readonly int $queueNumber,
        public readonly int $triagePriority,
        public readonly EntryStatus $status,
        /** How long this patient's consultation is expected to take. */
        public readonly int $expectedDurationSeconds,
        public readonly DurationSource $durationSource,
        /**
         * The instant this patient became callable (arrival), or null for a patient who has not arrived. Call-next
         * order (B5) sorts arrived patients on it. It is a generic sort key, so a later increment can feed the time
         * a skipped patient returned without changing this contract. A WAITING entry has arrived by definition (the
         * database says so too) and a REGISTERED entry has not.
         */
        public readonly ?CarbonImmutable $sortAt,
    ) {
        if ($queueNumber < 1 || $triagePriority < 0 || $triagePriority > 9 || $expectedDurationSeconds < 1 || $expectedDurationSeconds > self::MAX_SECONDS) {
            throw new InvalidArgumentException('Queue entry input out of range.');
        }
        if (($status === EntryStatus::Waiting && $sortAt === null) || ($status === EntryStatus::Registered && $sortAt !== null)) {
            throw new InvalidArgumentException('A waiting entry has arrived (sortAt set) and a registered one has not (sortAt null).');
        }
    }

    /** Entries that take up the doctor's time before a later entry's turn. */
    public function isAhead(): bool
    {
        return in_array($this->status, [EntryStatus::Registered, EntryStatus::Waiting, EntryStatus::Called], true);
    }

    /** Registered from the patient app but not yet at the clinic: counted ahead with wider uncertainty (E4). */
    public function isUnarrived(): bool
    {
        return $this->status === EntryStatus::Registered;
    }
}
