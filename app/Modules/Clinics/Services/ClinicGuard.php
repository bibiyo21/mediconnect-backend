<?php

namespace App\Modules\Clinics\Services;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;

/** Loads a clinic and checks the caller's clinic-scoped permission (403 when refused). */
final class ClinicGuard
{
    public function __construct(private readonly ClinicAccess $access) {}

    public function authorize(Request $request, string $permission, string $clinicId): Clinic
    {
        $clinic = Clinic::findOrFail($clinicId);
        /** @var User $user */
        $user = $request->user();

        abort_unless($this->access->allows($user, $permission, $clinic->id), 403, 'You do not have access to this clinic.');

        return $clinic;
    }

    /** @return array{update: bool, manage_hours: bool, manage_staff: bool} */
    public function abilities(Request $request, string $clinicId): array
    {
        /** @var User $user */
        $user = $request->user();

        return [
            'update' => $this->access->allows($user, 'clinics.update', $clinicId),
            'manage_hours' => $this->access->allows($user, 'clinics.manage_hours', $clinicId),
            'manage_staff' => $this->access->allows($user, 'clinics.manage_staff', $clinicId),
        ];
    }
}
