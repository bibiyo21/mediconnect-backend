<?php

namespace App\Modules\WalkIns\DTOs;

/** What a check-out did to the walk-in queues: counts only. */
final readonly class CheckOutQueueResult
{
    public function __construct(
        public int $queuesPaused,
        public int $entriesHeld,
        public int $entriesCancelled,
    ) {}
}
