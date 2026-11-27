<?php

namespace App\Modules\Consultations\Policies;

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationScope;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;

/**
 * Who may see and who may move a consultation (rbac-permissions.md §5; 5.7 D-O, D-J, B1 not built).
 *  - view: the level the ConsultationScope gives (full for the doctor and the patient or authorised dependant, restricted for global readers and
 *    clinic staff at the location). Anyone else gets 404, so a consultation's existence is not revealed.
 *  - start, end, interrupt and resume: only the consultation's own doctor, holding the DOCTOR permission (SD). Clinic staff and patients get 403 (B1, OQ-A, not granted).
 *  - D-16: a doctor is never the treating doctor of their own patient profile, so start is refused for that case with 403.
 */
final class ConsultationPolicy
{
    public function __construct(private readonly ConsultationScope $scope) {}

    public function view(User $user, Consultation $consultation): bool
    {
        return $this->scope->levelFor($this->scope->for($user), $consultation) !== null;
    }

    public function start(User $user, Consultation $consultation): bool
    {
        return $this->isOwnDoctor($user, $consultation) && $user->can('consultations.start') && ! $this->isSelfTreatment($user, $consultation);
    }

    public function end(User $user, Consultation $consultation): bool
    {
        return $this->isOwnDoctor($user, $consultation) && $user->can('consultations.end');
    }

    /** Pausing a running consultation needs the same permission as ending it (D-G). */
    public function interrupt(User $user, Consultation $consultation): bool
    {
        return $this->isOwnDoctor($user, $consultation) && $user->can('consultations.end');
    }

    /** Resuming needs the permission to start, since it puts the consultation back IN_PROGRESS (D-G). */
    public function resume(User $user, Consultation $consultation): bool
    {
        return $this->isOwnDoctor($user, $consultation) && $user->can('consultations.start');
    }

    private function isOwnDoctor(User $user, Consultation $consultation): bool
    {
        return DoctorProfile::query()->where('user_id', $user->id)->value('id') === $consultation->doctor_profile_id;
    }

    /** The patient account of the consultation (their own profile). A guardian's dependant is covered by the scope, not here (D-16). */
    private function isOwnPatient(User $user, Consultation $consultation): bool
    {
        return $consultation->patient_profile_id !== null
            && PatientProfile::query()->whereKey($consultation->patient_profile_id)->value('user_id') === $user->id;
    }

    /** D-16: the patient profile belongs to the doctor's own account. */
    private function isSelfTreatment(User $user, Consultation $consultation): bool
    {
        return $this->isOwnPatient($user, $consultation);
    }
}
