<?php

namespace App\Modules\Notifications\Realtime;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Users\Models\User;
use Illuminate\Support\Str;

/**
 * Server-side authorization of every channel subscription (rbac-permissions.md §8), using
 * the same checks as the API. Each method answers yes or no; a malformed id is a no, so a
 * hostile channel name never reaches a query.
 */
final class ChannelAuthorizer
{
    public function __construct(private readonly ClinicAccess $clinics) {}

    /** Your own user channel only. */
    public function user(User $user, string $userId): bool
    {
        return Str::isUuid($userId) && $user->id === $userId;
    }

    /** The doctor channel of the profile you own. Clinic staff are not doctors and cannot subscribe. */
    public function doctor(User $user, string $doctorProfileId): bool
    {
        return Str::isUuid($doctorProfileId)
            && DoctorProfile::whereKey($doctorProfileId)->where('user_id', $user->id)->exists();
    }

    /**
     * Presence for one location: an active member there (clinic-wide, or restricted to this
     * location) whose role holds `presence.manage`. No global-role shortcut: admins and
     * support read clinic data through the audited REST API, not through subscriptions.
     */
    public function clinic(User $user, string $locationId): bool
    {
        return Str::isUuid($locationId) && $this->clinics->memberAllowsAtLocation($user, 'presence.manage', $locationId);
    }

    /** The queue for one location: an active member there holding `walkins.view` (membership only, as above). */
    public function clinicQueue(User $user, string $locationId): bool
    {
        return Str::isUuid($locationId) && $this->clinics->memberAllowsAtLocation($user, 'walkins.view', $locationId);
    }
}
