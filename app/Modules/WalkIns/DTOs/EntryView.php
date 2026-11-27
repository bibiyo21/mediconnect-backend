<?php

namespace App\Modules\WalkIns\DTOs;

use App\Modules\WalkIns\Enums\ViewLevel;
use App\Modules\WalkIns\Estimation\WaitEstimate;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Shared\ValueObjects\Money;

/**
 * An entry together with what a reader of this audience may be told about it (the Resource only renders; every decision on
 * what to show is made when the view is built). $displayName is set only for staff and doctor readers. $includePhone only for
 * the staff detail of a guest. $showMinutes decides whether a staff estimate carries minutes (the board shows them for the next few waiting).
 */
final class EntryView
{
    public function __construct(
        public readonly WalkInQueueEntry $entry,
        public readonly WalkInQueue $queue,
        public readonly ViewLevel $level,
        public readonly string $timezone,
        public readonly ?string $doctorName,
        public readonly ?string $locationName,
        public readonly ?string $serviceName,
        public readonly ?Money $fee,
        public readonly ?WaitEstimate $estimate,
        public readonly bool $showMinutes = true,
        public readonly ?string $displayName = null,
        public readonly bool $includePhone = false,
        /** The consultation of a CALLED or IN_CONSULTATION entry, for the staff and doctor views only. */
        public readonly ?string $consultationId = null,
    ) {}
}
