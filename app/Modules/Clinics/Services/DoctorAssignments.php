<?php

namespace App\Modules\Clinics\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Enums\AssignmentStatus;
use App\Modules\Clinics\Events\DoctorAssignmentEnded;
use App\Modules\Clinics\Exceptions\InvalidAssignmentState;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Doctors\Enums\OnboardingStatus;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Notifications\Contracts\SendsOneTimeMessages;
use App\Modules\Users\Models\User;
use App\Shared\Enums\DeliveryChannel;
use App\Shared\Services\Fingerprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Doctor assignments to clinic locations (increment 2.10). A clinic invites,
 * the doctor accepts; either side can end it. Every change is audited.
 */
final class DoctorAssignments
{
    public function __construct(private readonly RecordsAudit $audit, private readonly SendsOneTimeMessages $messages) {}

    public function invite(ClinicLocation $location, string $licenceNumber, User $actor): DoctorClinicAssignment
    {
        /** @var DoctorProfile|null $doctor */
        $doctor = DoctorProfile::where('prc_license_number_hash', Fingerprint::of('prc:'.$licenceNumber))
            ->whereIn('onboarding_status', [OnboardingStatus::Verified->value, OnboardingStatus::Active->value])->first();
        if ($doctor === null) {
            throw ValidationException::withMessages(['prc_license_number' => ['No verified doctor on MediConnect has this licence number.']]);
        }
        if ($doctor->user_id === $actor->id) {
            throw ValidationException::withMessages(['prc_license_number' => ['You cannot assign yourself. Ask another clinic manager.']]);
        }

        $open = DoctorClinicAssignment::where('doctor_profile_id', $doctor->id)->where('clinic_location_id', $location->id)
            ->whereIn('status', [AssignmentStatus::Pending->value, AssignmentStatus::Active->value])->exists();
        if ($open) {
            throw ValidationException::withMessages(['prc_license_number' => ['This doctor is already assigned or invited here.']]);
        }

        $assignment = new DoctorClinicAssignment;
        $assignment->forceFill(['doctor_profile_id' => $doctor->id, 'clinic_location_id' => $location->id, 'status' => AssignmentStatus::Pending, 'requested_by' => $actor->id]);
        try {
            $assignment->save();
        } catch (UniqueConstraintViolationException) { // a simultaneous invitation won the race
            throw ValidationException::withMessages(['prc_license_number' => ['This doctor is already assigned or invited here.']]);
        }
        $this->audit->record('clinic.doctor_invited', 'doctor_clinic_assignment', $assignment->id, ['doctor_profile_id' => $doctor->id], clinicId: $location->clinic_id);
        $this->notify($doctor, 'A clinic invited you to practise with them on MediConnect. Open the app to accept or decline.');

        return $assignment;
    }

    public function accept(DoctorClinicAssignment $assignment, User $doctorUser): DoctorClinicAssignment
    {
        return $this->change($assignment, AssignmentStatus::Pending, function (DoctorClinicAssignment $a) use ($doctorUser) {
            $a->forceFill(['status' => AssignmentStatus::Active, 'approved_by' => $doctorUser->id, 'effective_from' => now()])->save();
            $this->audit->record('clinic.doctor_assignment_accepted', 'doctor_clinic_assignment', $a->id, clinicId: $a->location->clinic_id);
        });
    }

    /** Ends an active assignment, or withdraws or declines a pending one. */
    public function end(DoctorClinicAssignment $assignment, User $actor, string $reason): DoctorClinicAssignment
    {
        $wasActive = $assignment->status === AssignmentStatus::Active;
        $ended = $this->change($assignment, null, function (DoctorClinicAssignment $a) use ($actor, $reason) {
            $a->forceFill(['status' => AssignmentStatus::Ended, 'effective_until' => now(), 'ended_by' => $actor->id, 'end_reason' => $reason])->save();
            $this->audit->record('clinic.doctor_assignment_ended', 'doctor_clinic_assignment', $a->id, ['reason' => $reason], clinicId: $a->location->clinic_id);
        });
        if ($wasActive) {
            DoctorAssignmentEnded::dispatch($ended->doctor_profile_id, $ended->clinic_location_id, $actor->id);
        }

        return $ended;
    }

    /** Locks the row and applies $apply when it is in $from (null: PENDING or ACTIVE). */
    private function change(DoctorClinicAssignment $assignment, ?AssignmentStatus $from, callable $apply): DoctorClinicAssignment
    {
        return DB::transaction(function () use ($assignment, $from, $apply) {
            /** @var DoctorClinicAssignment $locked */
            $locked = DoctorClinicAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $allowed = $from !== null ? [$from] : [AssignmentStatus::Pending, AssignmentStatus::Active];
            if (! in_array($locked->status, $allowed, true)) {
                throw InvalidAssignmentState::is($locked->status);
            }
            $apply($locked);

            return $locked->load(['doctorProfile', 'location.clinic']);
        });
    }

    private function notify(DoctorProfile $doctor, string $notice): void
    {
        $user = $doctor->user;
        if ($user->email !== null) {
            $this->messages->sendNotice(DeliveryChannel::Email, $user->email, $notice);
        } elseif ($user->mobile_e164 !== null) {
            $this->messages->sendNotice(DeliveryChannel::Sms, $user->mobile_e164, $notice);
        }
    }
}
