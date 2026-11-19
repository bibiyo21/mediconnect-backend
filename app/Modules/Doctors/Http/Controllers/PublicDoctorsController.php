<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Modules\Doctors\Http\Requests\ListPublicDoctorsRequest;
use App\Modules\Doctors\Http\Resources\DoctorServiceResource;
use App\Modules\Doctors\Http\Resources\PublicDoctorResource;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The public doctor directory (increment 2.11, CLAUDE.md §5.2). Only ACTIVE
 * doctors exist here; anything else is a 404. Plain PostgreSQL listing with
 * page pagination; ranking and search arrive with Phase 3.
 */
final class PublicDoctorsController
{
    private const WITH = ['specialties.specialty', 'specialties.subspecialty', 'activeAssignments.location.clinic'];

    public function index(ListPublicDoctorsRequest $request): JsonResponse
    {
        $filter = (array) $request->validated('filter', []);
        $page = self::listed()
            ->with(self::WITH)
            ->when(isset($filter['specialty_id']), fn (Builder $q) => $q->whereHas('specialties', fn (Builder $s) => $s->where('specialty_id', $filter['specialty_id'])->where('verification_status', 'CONFIRMED')))
            ->when(isset($filter['city']), fn (Builder $q) => $q->whereHas('activeAssignments.location', fn (Builder $l) => $l->whereRaw('lower(city) = lower(?)', [$filter['city']])->where('status', 'ACTIVE')))
            ->when(isset($filter['clinic_id']), fn (Builder $q) => $q->whereHas('activeAssignments.location', fn (Builder $l) => $l->where('clinic_id', $filter['clinic_id'])))
            ->when(isset($filter['consultation_type']), fn (Builder $q) => $q->whereExists(fn ($s) => $s->from('doctor_services')->whereColumn('doctor_services.doctor_profile_id', 'doctor_profiles.id')->where('doctor_services.status', 'ACTIVE')->where('consultation_type', $filter['consultation_type'])))
            ->orderBy('display_name')->orderBy('id')
            ->paginate((int) $request->validated('per_page', 20));

        /** @var list<DoctorProfile> $doctors */
        $doctors = $page->items();
        $languages = self::languagesFor(array_map(fn (DoctorProfile $d) => $d->id, $doctors));

        return ApiResponse::success(array_map(fn (DoctorProfile $d) => new PublicDoctorResource($d, $languages[$d->id] ?? []), $doctors), meta: [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }

    public function show(string $doctorId): JsonResponse
    {
        $doctor = self::listed()->with([...self::WITH, 'education'])->findOrFail($doctorId);

        return ApiResponse::success(new PublicDoctorResource($doctor, self::languagesFor([$doctor->id])[$doctor->id] ?? [], detail: true));
    }

    /** Services patients can see: online, or at a location where the doctor practises now. */
    public function services(string $doctorId): JsonResponse
    {
        $doctor = self::listed()->findOrFail($doctorId);
        $locations = $doctor->activeAssignments()->pluck('clinic_location_id');
        $services = DoctorService::with('location')->where('doctor_profile_id', $doctor->id)->where('status', 'ACTIVE')
            ->where(fn (Builder $q) => $q->whereNull('clinic_location_id')->orWhereIn('clinic_location_id', $locations))
            ->orderBy('consultation_type')->orderBy('name')->get();

        return ApiResponse::success(DoctorServiceResource::collection($services));
    }

    /** @return Builder<DoctorProfile> */
    private static function listed(): Builder
    {
        return DoctorProfile::query()->where('onboarding_status', 'ACTIVE');
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    private static function languagesFor(array $ids): array
    {
        $map = [];
        foreach (DB::table('doctor_languages')->whereIn('doctor_profile_id', $ids)->orderBy('language_code')->get(['doctor_profile_id', 'language_code']) as $row) {
            $map[(string) $row->doctor_profile_id][] = (string) $row->language_code;
        }

        return $map;
    }
}
