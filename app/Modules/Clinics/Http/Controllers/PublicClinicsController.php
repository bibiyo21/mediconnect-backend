<?php

namespace App\Modules\Clinics\Http\Controllers;

use App\Modules\Clinics\Http\Requests\ListPublicClinicsRequest;
use App\Modules\Clinics\Http\Resources\PublicClinicDetailResource;
use App\Modules\Clinics\Http\Resources\PublicClinicResource;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\ClinicOperatingHour;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * The public clinic directory (increment 2.11). Only ACTIVE clinics and their
 * ACTIVE locations; doctors listed only when ACTIVE and assigned there now.
 */
final class PublicClinicsController
{
    public function index(ListPublicClinicsRequest $request): JsonResponse
    {
        $city = $request->validated('filter.city');
        $page = self::listed()
            ->when($city !== null, fn (Builder $q) => $q->whereHas('locations', fn (Builder $l) => $l->where('status', 'ACTIVE')->whereRaw('lower(city) = lower(?)', [$city])))
            ->orderBy('name')->orderBy('id')
            ->paginate((int) $request->validated('per_page', 20));

        return ApiResponse::success(PublicClinicResource::collection($page->getCollection()), meta: [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]);
    }

    public function show(string $clinicId): JsonResponse
    {
        $clinic = self::listed()->with(['holidays'])->findOrFail($clinicId);

        return ApiResponse::success(new PublicClinicDetailResource($clinic));
    }

    public function operatingHours(string $clinicId): JsonResponse
    {
        $clinic = self::listed()->findOrFail($clinicId);

        return ApiResponse::success($clinic->locations->map(fn (ClinicLocation $l) => [
            'clinic_location_id' => $l->id,
            'location_name' => $l->name,
            'timezone' => $l->timezone,
            'operating_hours' => $l->operatingHours->map(fn (ClinicOperatingHour $h) => ['day_of_week' => $h->day_of_week, 'opens_at' => substr($h->opens_at, 0, 5), 'closes_at' => substr($h->closes_at, 0, 5)])->values()->all(),
        ])->values()->all());
    }

    /** ACTIVE doctors who practise at this clinic now, with confirmed specialties only (D-40). */
    public function doctors(string $clinicId): JsonResponse
    {
        $clinic = self::listed()->findOrFail($clinicId);
        $assignments = DoctorClinicAssignment::with(['doctorProfile.specialties.specialty', 'location'])
            ->where('status', 'ACTIVE')->whereIn('clinic_location_id', $clinic->locations->modelKeys())
            ->whereHas('doctorProfile', fn (Builder $q) => $q->where('onboarding_status', 'ACTIVE'))->get();

        $doctors = $assignments->groupBy('doctor_profile_id')->map(function ($rows) {
            /** @var DoctorClinicAssignment $first */
            $first = $rows->first();
            $d = $first->doctorProfile;
            $primary = $d->specialties->first(fn ($s) => $s->is_primary && $s->isConfirmed());

            return [
                'id' => $d->id,
                'display_name' => $d->display_name,
                'professional_title' => $d->professional_title,
                'primary_specialty' => $primary?->specialty->name,
                /** @var list<string> */
                'location_ids' => $rows->pluck('clinic_location_id')->values()->all(),
            ];
        })->sortBy('display_name')->values()->all();

        return ApiResponse::success($doctors);
    }

    /** @return Builder<Clinic> */
    private static function listed(): Builder
    {
        return Clinic::query()->where('status', 'ACTIVE')
            ->whereHas('locations', fn (Builder $l) => $l->where('status', 'ACTIVE'))
            ->with(['organization', 'locations' => fn ($l) => $l->where('status', 'ACTIVE')->orderBy('name'), 'locations.operatingHours', 'locations.services']);
    }
}
