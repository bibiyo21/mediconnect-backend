<?php

namespace App\Modules\Presence\Services;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Presence\Enums\PresenceChangeMethod;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Who may change a doctor's presence (rbac-permissions.md `presence.*`): the doctor themself
 * (`presence.check_in`, scope SD), or someone holding `presence.manage` at the location
 * (scope AC through ClinicAccess::allowsAtLocation, so staff restricted to another location
 * are refused; scope G for administrators). Acting on behalf always names the location, so a
 * 403 is decided before anything about the doctor's whereabouts is revealed.
 */
final class PresenceAccess
{
    public function __construct(private readonly ClinicAccess $clinics) {}

    /**
     * Someone probing other doctors' presence shows up in the logs: ids only, at most one line a minute per
     * user and location (PRESENCE_ONBEHALF_DENIED), so it cannot flood them either.
     */
    private function logDenied(User $user, string $doctorId, ?string $locationId): void
    {
        RateLimiter::attempt("presence-denied:{$user->id}:{$locationId}", 1, function () use ($user, $doctorId, $locationId): void {
            Log::warning('Presence action on behalf refused', ['metric' => 'PRESENCE_ONBEHALF_DENIED', 'user_id' => $user->id, 'doctor_id' => $doctorId, 'clinic_location_id' => $locationId]);
        }, 60);
    }

    /**
     * @return array{doctor: string, method: PresenceChangeMethod}
     */
    public function resolve(User $user, ?string $doctorId, ?string $locationId): array
    {
        $own = DoctorProfile::where('user_id', $user->id)->value('id');
        $own = is_string($own) ? $own : null;

        if ($doctorId === null || $doctorId === $own) {
            abort_unless($own !== null && $user->can('presence.check_in'), 403, 'You do not have permission to change your own presence.');

            return ['doctor' => $own, 'method' => PresenceChangeMethod::DoctorApp];
        }

        if ($locationId === null || ! $this->clinics->allowsAtLocation($user, 'presence.manage', $locationId)) {
            $this->logDenied($user, $doctorId, $locationId);
            abort(403, 'You do not have permission to manage presence at this location.');
        }

        return [
            'doctor' => $doctorId,
            'method' => $this->clinics->memberAllowsAtLocation($user, 'presence.manage', $locationId) ? PresenceChangeMethod::StaffConsole : PresenceChangeMethod::Admin,
        ];
    }
}
