<?php

namespace App\Modules\WalkIns\Enums;

/**
 * The three triage levels the API exposes (decision 88). Stored as a small integer so the call-next order
 * can sort on it; only the relative order matters, and 0 means "no override needed" at call-next.
 * The API never accepts the integer, only the name.
 */
enum PriorityLevel: int
{
    case Normal = 0;
    case Priority = 5;
    case Urgent = 9;

    /** A level above normal needs a reason code (and an audited override at call-next). */
    public function needsReason(): bool
    {
        return $this !== self::Normal;
    }
}
