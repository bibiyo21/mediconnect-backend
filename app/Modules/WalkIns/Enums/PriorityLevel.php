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

    /** The name the API uses (NORMAL, PRIORITY, URGENT): the API never accepts or returns the integer. */
    public function apiName(): string
    {
        return strtoupper($this->name);
    }

    public static function fromApi(string $name): self
    {
        foreach (self::cases() as $case) {
            if ($case->apiName() === $name) {
                return $case;
            }
        }

        throw new \ValueError("Unknown priority level: {$name}");
    }

    /** A level above normal needs a reason code (and an audited override at call-next). */
    public function needsReason(): bool
    {
        return $this !== self::Normal;
    }
}
