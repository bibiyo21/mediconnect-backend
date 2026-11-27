<?php

namespace App\Modules\Consultations\Contracts;

use App\Modules\Consultations\DTOs\WalkInCallIntake;

/** How a called walk-in becomes a consultation, and how that is undone. Both methods run inside the caller's transaction (queue lock held). */
interface ConsultationIntake
{
    /**
     * Opens the SCHEDULED consultation of a called walk-in entry and returns its id. Idempotent per entry: an existing SCHEDULED
     * (or WAITING_ROOM) row is returned as is, a CANCELLED row (skipped, then returned and called again) is revived to SCHEDULED.
     *
     * @throws \LogicException when the consultation is IN_PROGRESS, INTERRUPTED or COMPLETED (the entry must not be callable)
     */
    public function openForWalkIn(WalkInCallIntake $call): string;

    /**
     * Cancels the SCHEDULED consultation of an entry that left CALLED (skip, no-show, cancel) and revokes any care relationship
     * for it. Idempotent: no row, a CANCELLED or a COMPLETED row changes nothing. The reason is kept by the queue (its event and audit row),
     * not here: consultations store none.
     *
     * @throws \LogicException when the consultation is IN_PROGRESS or INTERRUPTED
     */
    public function cancelForWalkIn(string $entryId): void;
}
