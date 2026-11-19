<?php

namespace App\Modules\Doctors\Http\Controllers;

use App\Modules\Doctors\Http\Requests\CreateDoctorServiceRequest;
use App\Modules\Doctors\Http\Requests\UpdateDoctorServiceRequest;
use App\Modules\Doctors\Http\Resources\DoctorServiceResource;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Doctors\Services\DoctorServices;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The doctor's own services and fees (increment 2.10). Another doctor's service is a 404. */
final class MyServicesController
{
    public function __construct(private readonly DoctorServices $services) {}

    public function index(Request $request): JsonResponse
    {
        $rows = DoctorService::with('location')->where('doctor_profile_id', $this->profile($request)->id)->where('status', 'ACTIVE')
            ->orderBy('consultation_type')->orderBy('name')->get();

        return ApiResponse::success(DoctorServiceResource::collection($rows));
    }

    public function store(CreateDoctorServiceRequest $request): JsonResponse
    {
        $service = $this->services->create($this->profile($request), $request->validated(), $this->user($request));

        return ApiResponse::success(new DoctorServiceResource($service->load('location')), status: 201);
    }

    public function update(UpdateDoctorServiceRequest $request, string $serviceId): JsonResponse
    {
        $service = $this->services->change($this->own($request, $serviceId), $request->validated(), $this->user($request));

        return ApiResponse::success(new DoctorServiceResource($service->load('location')));
    }

    public function destroy(Request $request, string $serviceId): JsonResponse
    {
        $this->services->end($this->own($request, $serviceId));

        return ApiResponse::success(null);
    }

    private function own(Request $request, string $serviceId): DoctorService
    {
        return DoctorService::where('doctor_profile_id', $this->profile($request)->id)->where('status', 'ACTIVE')->whereKey($serviceId)->firstOrFail();
    }

    private function profile(Request $request): DoctorProfile
    {
        return DoctorProfile::where('user_id', $this->user($request)->id)->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
