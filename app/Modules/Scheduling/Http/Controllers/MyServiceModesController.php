<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Scheduling\Enums\ServiceMode;
use App\Modules\Scheduling\Http\Requests\SetServiceModeRequest;
use App\Modules\Scheduling\Http\Resources\ServiceModeResource;
use App\Modules\Scheduling\Services\ServiceModes;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The doctor's own service modes (increment 4.2, `schedules.*` at scope SD). */
final class MyServiceModesController
{
    public function __construct(private readonly ServiceModes $modes) {}

    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile($request, 'schedules.view');
        $services = DoctorService::with('location')->where('doctor_profile_id', $profile->id)->where('status', 'ACTIVE')->orderBy('consultation_type')->orderBy('name')->get();
        $modes = $this->modes->forServices($services->pluck('id'));

        return ApiResponse::success(ServiceModeResource::collection($services->map(fn (DoctorService $s) => ['service' => $s, 'mode' => $modes[$s->id] ?? null])));
    }

    public function update(SetServiceModeRequest $request, string $serviceId): JsonResponse
    {
        $profile = $this->profile($request, 'schedules.update');
        $service = DoctorService::with('location')->where('doctor_profile_id', $profile->id)->where('status', 'ACTIVE')->whereKey($serviceId)->firstOrFail();
        /** @var User $actor */
        $actor = $request->user();
        $mode = $this->modes->set($service, ServiceMode::from((string) $request->validated('service_mode')), $actor);

        return ApiResponse::success(new ServiceModeResource(['service' => $service, 'mode' => $mode]));
    }

    private function profile(Request $request, string $permission): DoctorProfile
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can($permission), 403, 'You do not have permission to manage schedules.');

        return DoctorProfile::where('user_id', $user->id)->firstOrFail();
    }
}
