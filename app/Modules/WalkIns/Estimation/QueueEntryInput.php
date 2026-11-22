<?php

namespace App\Modules\WalkIns\Estimation;

use App\Modules\WalkIns\Enums\EntryStatus;
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
    ) {
        if ($queueNumber < 1 || $triagePriority < 0 || $triagePriority > 9 || $expectedDurationSeconds < 1 || $expectedDurationSeconds > self::MAX_SECONDS) {
            throw new InvalidArgumentException('Queue entry input out of range.');
        }
    }

    /** Entries that take up the doctor's time before a later entry's turn. */
    public function isAhead(): bool
    {
        return in_array($this->status, [EntryStatus::Registered, EntryStatus::Waiting, EntryStatus::Called], true);
    }
}
