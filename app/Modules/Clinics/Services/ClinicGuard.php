<?php

namespace App\Modules\Clinics\Services;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;

/**
 * Loads a clinic and checks the caller's clinic-scoped permission (403 when refused).
 * Pick the check that matches the action (see ClinicAccess): authorize() is only the
 * clinic-level gate (reads); authorizeWide() for the clinic itself or clinic-wide records;
 * authorizeAtLocation() for anything on one location.
 */
final class ClinicGuard
{
    public function __construct(private readonly ClinicAccess $access) {}

    /** Clinic-level gate: any member (or global role) holding the permission. Reads only; never for a single-location action. */
    public function authorize(Request $request, string $permission, string $clinicId): Clinic
    {
        $clinic = Clinic::findOrFail($clinicId);
        abort_unless($this->access->allows($this->user($request), $permission, $clinic->id), 403, 'You do not have access to this clinic.');

        return $clinic;
    }

    /** The clinic itself or a clinic-wide record: needs an unrestricted membership (or a global role). */
    public function authorizeWide(Request $request, string $permission, string $clinicId): Clinic
    {
        $clinic = Clinic::findOrFail($clinicId);
        abort_unless($this->access->allowsClinicWide($this->user($request), $permission, $clinicId), 403, 'This needs access to the whole clinic.');

        return $clinic;
    }

    /** One location of this clinic (404 for a location of another clinic): needs access at that location. */
    public function authorizeAtLocation(Request $request, string $permission, string $clinicId, string $locationId): Clinic
    {
        $clinic = Clinic::findOrFail($clinicId);
        ClinicLocation::where('clinic_id', $clinic->id)->findOrFail($locationId);
        abort_unless($this->access->allowsAtLocation($this->user($request), $permission, $locationId), 403, 'You do not have access to this location.');

        return $clinic;
    }

    /**
     * What the caller can do for the clinic as a whole (clinic-wide access). A member restricted
     * to one location sees false for clinic-level changes; their location rights apply per location.
     *
     * @return array{update: bool, manage_hours: bool, manage_staff: bool, manage_doctors: bool}
     */
    public function abilities(Request $request, string $clinicId): array
    {
        $user = $this->user($request);

        return [
            'update' => $this->access->allowsClinicWide($user, 'clinics.update', $clinicId),
            'manage_hours' => $this->access->allowsClinicWide($user, 'clinics.manage_hours', $clinicId),
            'manage_staff' => $this->access->allowsClinicWide($user, 'clinics.manage_staff', $clinicId),
            'manage_doctors' => $this->access->allowsClinicWide($user, 'doctors.manage_assignments', $clinicId),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
