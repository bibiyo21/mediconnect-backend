<?php

namespace App\Modules\Clinics\Contracts;

use App\Modules\Users\Models\User;

/**
 * Clinic-scoped authorization (rbac-permissions.md §1, scope AC; D-17).
 * A permission held at scope G (ADMIN, SUPER_ADMIN) applies to every clinic;
 * held at scope AC it applies only where the user has an ACTIVE membership.
 *
 * Membership can be narrowed to one location (`clinic_members.clinic_location_id`,
 * null = every location of the clinic; database-schema.md §4). Pick the method that
 * matches what the action touches:
 *
 * - one location (schedules, queues, presence, hours, services, assignments, staff at a
 *   location): allowsAtLocation();
 * - the clinic itself or a clinic-wide record (clinic PATCH, new locations, clinic-wide
 *   holidays, clinic-wide staff): allowsClinicWide();
 * - allows() is only the clinic-level gate "is this person a member here at all": reads and
 *   the first check before a narrower one. It must never be used to authorize an action on a
 *   single location, because a member restricted to location A passes it.
 */
interface ClinicAccess
{
    /** Clinic-level gate: global grant, or any active membership of the clinic whatever its location. Never use it for a single-location action. */
    public function allows(User $user, string $permission, string $clinicId): bool;

    /** Whether the permission applies at this location: global grant, clinic-wide membership, or membership narrowed to exactly this location. */
    public function allowsAtLocation(User $user, string $permission, string $locationId): bool;

    /** Whether the permission applies to the whole clinic: global grant, or an active membership with no location restriction. */
    public function allowsClinicWide(User $user, string $permission, string $clinicId): bool;

    /**
     * Membership only, no global shortcut: an active membership that covers this location and
     * whose clinic role holds the permission. Real-time channels use this: global roles read
     * clinic data through the audited REST API, not through subscriptions.
     */
    public function memberAllowsAtLocation(User $user, string $permission, string $locationId): bool;

    /**
     * The locations of the clinic where the permission applies to the user (empty when none).
     * Used to narrow clinic-wide lists for location-restricted staff.
     *
     * @return list<string>
     */
    public function allowedLocationIds(User $user, string $permission, string $clinicId): array;

    /** @return list<string> ids of clinics where the user has an active membership */
    public function memberClinicIds(User $user): array;
}
