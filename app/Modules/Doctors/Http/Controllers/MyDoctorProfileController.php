<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Doctors\Http\Requests\StartDoctorOnboardingRequest;
use App\Modules\Doctors\Http\Requests\UpdateMyDoctorProfileRequest;
use App\Modules\Doctors\Http\Resources\MyDoctorProfileResource;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Services\DoctorOnboarding;
use App\Modules\Doctors\Services\ProfileCompleteness;
use App\Modules\Rbac\Contracts\AssignsRoles;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use App\Shared\Services\Fingerprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** POST /doctors (start onboarding), GET and PATCH /doctors/me (own profile only). */
final class MyDoctorProfileController
{
    public function __construct(
        private readonly DoctorOnboarding $onboarding,
        private readonly ProfileCompleteness $completeness,
        private readonly RecordsAudit $audit,
    ) {}

    public function store(StartDoctorOnboardingRequest $request, AssignsRoles $roles): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (DoctorProfile::where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['display_name' => ['You already have a doctor profile.']]);
        }

        $profile = DB::transaction(function () use ($request, $user, $roles) {
            $profile = new DoctorProfile(['display_name' => trim((string) $request->input('display_name')), 'professional_title' => $request->input('professional_title')]);
            $profile->user_id = $user->id;
            try {
                $profile->save();
            } catch (UniqueConstraintViolationException) {
                // A second, simultaneous request from the same user created the profile first.
                throw ValidationException::withMessages(['display_name' => ['You already have a doctor profile.']]);
            }
            $roles->assign($user, Role::Doctor);
            $this->onboarding->started($profile, $user->id);
            $this->refreshCompleteness($profile);
            $this->audit->record('doctor.onboarding_started', 'doctor_profile', $profile->id);

            return $profile;
        });

        return ApiResponse::success(new MyDoctorProfileResource($this->loaded($profile)), status: 201);
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(new MyDoctorProfileResource($this->loaded($this->own($request))));
    }

    public function update(UpdateMyDoctorProfileRequest $request): JsonResponse
    {
        $profile = $this->own($request);
        $data = $request->validated();

        DB::transaction(function () use ($profile, $data) {
            $profile->fill(array_intersect_key($data, array_flip(['display_name', 'professional_title', 'biography', 'medical_school', 'years_experience', 'gender'])));
            $changed = array_keys($profile->getDirty());

            if (array_key_exists('prc_license_number', $data) && $this->setLicence($profile, (string) $data['prc_license_number'])) {
                $changed[] = 'prc_license_number';
            }

            try {
                $profile->save();
            } catch (UniqueConstraintViolationException) {
                // Another account saved the same licence number between the check and this save.
                throw ValidationException::withMessages(['prc_license_number' => ['This licence number is already registered to another account.']]);
            }

            if (array_key_exists('languages', $data)) {
                DB::table('doctor_languages')->where('doctor_profile_id', $profile->id)->delete();
                DB::table('doctor_languages')->insert(array_map(fn (string $c) => ['doctor_profile_id' => $profile->id, 'language_code' => $c], array_values($data['languages'])));
                $changed[] = 'languages';
            }
            if (array_key_exists('specialties', $data)) {
                $profile->specialties()->delete();
                foreach ($data['specialties'] as $s) {
                    $profile->specialties()->create(['specialty_id' => $s['specialty_id'], 'subspecialty_id' => $s['subspecialty_id'] ?? null, 'is_primary' => (bool) ($s['is_primary'] ?? false), 'board_certified' => (bool) ($s['board_certified'] ?? false)]);
                }
                $changed[] = 'specialties';
            }
            foreach (['education', 'experience'] as $list) {
                if (array_key_exists($list, $data)) {
                    $profile->{$list}()->delete();
                    foreach ($data[$list] as $row) {
                        $profile->{$list}()->create($row);
                    }
                    $changed[] = $list;
                }
            }

            $this->refreshCompleteness($profile);
            if ($changed !== []) {
                $this->audit->record('doctor.profile_updated', 'doctor_profile', $profile->id, ['fields' => array_values(array_unique($changed))]);
            }
        });

        return ApiResponse::success(new MyDoctorProfileResource($this->loaded($profile->refresh())));
    }

    /** Sets a new licence number; returns false when it is the one already stored. */
    private function setLicence(DoctorProfile $profile, string $number): bool
    {
        $hash = Fingerprint::of('prc:'.$number);
        if ($hash === $profile->prc_license_number_hash) {
            return false;
        }
        if ($profile->onboarding_status->licenceLocked()) {
            throw ValidationException::withMessages(['prc_license_number' => ['The licence number cannot change while it is being reviewed or after it was verified. Contact support.']]);
        }
        if (DoctorProfile::where('prc_license_number_hash', $hash)->whereKeyNot($profile->id)->exists()) {
            throw ValidationException::withMessages(['prc_license_number' => ['This licence number is already registered to another account.']]);
        }
        $profile->prc_license_number_encrypted = $number;
        $profile->prc_license_number_hash = $hash;

        return true;
    }

    private function refreshCompleteness(DoctorProfile $profile): void
    {
        $profile->forceFill(['profile_completeness' => $this->completeness->score($profile)])->save();
    }

    private function own(Request $request): DoctorProfile
    {
        /** @var User $user */
        $user = $request->user();

        return DoctorProfile::where('user_id', $user->id)->firstOrFail();
    }

    private function loaded(DoctorProfile $profile): DoctorProfile
    {
        return $profile->load(['specialties.specialty', 'specialties.subspecialty', 'education', 'experience']);
    }
}
