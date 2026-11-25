<?php

namespace App\Modules\Presence\Contracts;

use App\Modules\Presence\DTOs\AdmissionPresence;

/**
 * The doctor's presence at a location, for walk-in admission (decisions 96 and 168). Presence stays inside the Presence
 * module; the WalkIns module reads it only through here.
 *
 * Phase 5 lock order: account advisory lock, patient advisory lock, doctor_presence_sessions FOR SHARE (this contract),
 * location/date advisory lock (queue creation only), walkin_queues FOR UPDATE, entries, consultations and appointments.
 */
interface PresenceAdmission
{
    /**
     * Reads the doctor's open session with a FOR SHARE lock, so a check-out (session FOR UPDATE, then queues) waits for the
     * registration in progress. Must run inside a database transaction. If the session row disappears while waiting
     * (a check-out committed), today's closed session at the location is looked up and OUT is returned, not NOT_CHECKED_IN.
     * A lock wait past the transaction's lock_timeout raises the database's 55P03; the caller maps it.
     */
    public function lockShared(string $doctorProfileId, string $locationId): AdmissionPresence;

    /** The same answer without a lock, for previews and boards. Advisory: admission decides under lock. */
    public function current(string $doctorProfileId, string $locationId): AdmissionPresence;
}
