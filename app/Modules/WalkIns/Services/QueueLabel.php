<?php

namespace App\Modules\WalkIns\Services;

/** The number a patient is told (decision 93): prefix, a dash, and the number zero-padded to at least two digits (A-07, A-123). Derived, never stored. */
final class QueueLabel
{
    public static function of(?string $prefix, int $number): string
    {
        return ($prefix !== null && $prefix !== '' ? $prefix.'-' : '').str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    }
}
