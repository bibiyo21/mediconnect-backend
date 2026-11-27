<?php

namespace App\Modules\Consultations\Services;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Consultations\DTOs\ReadScope;
use App\Modules\Consultations\Enums\ConsultationView;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Patients\Models\Dependent;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may read which consultation, and at what level (rbac-permissions §8; brief §4 5.7-2; B1 not built, so writes stay with the doctor).
 *  - Full: the consultation's own doctor, and the patient it is about (their own account or a dependant they are authorised for).
 *  - Restricted: a global-scope reader (ADMIN, SUPPORT_AGENT, MEDICAL_ADMIN), and clinic staff with consultations.view at its location.
 *  - Otherwise nothing: the caller gets a 404 on show and no row on the list.
 * SUPER_ADMIN excludes consultations.view in config/rbac.php, so it has no standing here (no RBAC change in 5.7).
 */
final class ConsultationScope
{
    public function __construct(private readonly ClinicAccess $clinics) {}

    public function for(User $user): ReadScope
    {
        $doctor = DoctorProfile::query()->where('user_id', $user->id)->value('id');
        $own = PatientProfile::query()->where('user_id', $user->id)->pluck('id')->all();
        $dependants = Dependent::query()
            ->authorized()
            ->whereHas('guardian', fn (Builder $q) => $q->where('user_id', $user->id))
            ->pluck('dependent_patient_profile_id')->all();
        $global = $this->globalReader($user);

        return new ReadScope(
            is_string($doctor) ? $doctor : null,
            array_values(array_unique(array_map('strval', [...$own, ...$dependants]))),
            $global ? [] : $this->staffLocations($user),
            $global,
        );
    }

    public function levelFor(ReadScope $scope, Consultation $consultation): ?ConsultationView
    {
        if ($scope->doctor !== null && $consultation->doctor_profile_id === $scope->doctor) {
            return ConsultationView::Full;
        }
        if ($consultation->patient_profile_id !== null && in_array($consultation->patient_profile_id, $scope->profiles, true)) {
            return ConsultationView::Full;
        }
        if ($scope->global) {
            return ConsultationView::Restricted;
        }
        if ($consultation->clinic_location_id !== null && in_array((string) $consultation->clinic_location_id, $scope->locations, true)) {
            return ConsultationView::Restricted;
        }

        return null;
    }

    /**
     * Narrows a query to the rows the scope may see. A global reader sees every row (restricted or full by level).
     *
     * @param  Builder<Consultation>  $query
     * @return Builder<Consultation>
     */
    public function applyTo(Builder $query, ReadScope $scope): Builder
    {
        if ($scope->global) {
            return $query;
        }
        if ($scope->doctor === null && $scope->profiles === [] && $scope->locations === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $w) use ($scope): void {
            $w->whereRaw('1 = 0');
            if ($scope->doctor !== null) {
                $w->orWhere('doctor_profile_id', $scope->doctor);
            }
            if ($scope->profiles !== []) {
                $w->orWhereIn('patient_profile_id', $scope->profiles);
            }
            if ($scope->locations !== []) {
                $w->orWhereIn('clinic_location_id', $scope->locations);
            }
        });
    }

    /** A global-scope role holds consultations.view with scope G in config/rbac.php. */
    private function globalReader(User $user): bool
    {
        // The permission key holds a dot, so it is read from the role's array, not by dot notation.
        $grants = config('rbac.grants');

        return $user->getRoleNames()->contains(fn (string $role): bool => ($grants[$role]['consultations.view'] ?? null) === 'G');
    }

    /** @return list<string> the locations where the user may read consultations as clinic staff */
    private function staffLocations(User $user): array
    {
        $locations = [];
        foreach ($this->clinics->memberClinicIds($user) as $clinicId) {
            foreach ($this->clinics->allowedLocationIds($user, 'consultations.view', $clinicId) as $locationId) {
                $locations[] = $locationId;
            }
        }

        return array_values(array_unique($locations));
    }
}
