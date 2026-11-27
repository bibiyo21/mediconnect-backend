<?php

namespace App\Modules\Presence\Contracts;

/**
 * Drops the server-side public status of a doctor after a change that Presence did not make itself (a consultation start or end, which
 * changes BUSY; plan 5.7 D-H). Call it after commit. Presence keeps owning its cache.
 */
interface PresenceVisibility
{
    public function forgetPublic(string $doctorProfileId): void;
}
