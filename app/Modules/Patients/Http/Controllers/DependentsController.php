<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Modules\Patients\Actions\ManageDependents;
use App\Modules\Patients\Http\Requests\StoreDependentRequest;
use App\Modules\Patients\Http\Requests\UpdateDependentRequest;
use App\Modules\Patients\Http\Resources\DependentResource;
use App\Modules\Patients\Models\Dependent;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * /patients/me/dependents: the signed-in guardian's AUTHORIZED dependents only.
 * Another guardian's dependent, a revoked link and a missing id all give 404.
 */
final class DependentsController
{
    public function __construct(private readonly ManageDependents $dependents) {}

    public function index(Request $request): JsonResponse
    {
        $links = Dependent::authorized()
            ->where('guardian_patient_profile_id', $this->guardian($request)->id)
            ->with('dependent')
            ->orderBy('authorized_at')
            ->get();

        return ApiResponse::success(DependentResource::collection($links));
    }

    public function store(StoreDependentRequest $request): JsonResponse
    {
        $link = $this->dependents->add($this->guardian($request), $request->person());

        return ApiResponse::success(new DependentResource($link), status: 201);
    }

    public function show(Request $request, string $dependentId): JsonResponse
    {
        return ApiResponse::success(new DependentResource($this->link($request, $dependentId)));
    }

    public function update(UpdateDependentRequest $request, string $dependentId): JsonResponse
    {
        $link = $this->link($request, $dependentId);
        Gate::authorize('update', $link->dependent);

        return ApiResponse::success(new DependentResource($this->dependents->update($link, $request->changes())));
    }

    public function destroy(Request $request, string $dependentId): JsonResponse
    {
        $guardian = $this->guardian($request);
        $this->dependents->remove($this->link($request, $dependentId), $guardian);

        return ApiResponse::success(message: 'The family member was removed from your account. Their records are kept.');
    }

    private function guardian(Request $request): PatientProfile
    {
        /** @var User $user */
        $user = $request->user();

        return PatientProfile::where('user_id', $user->id)->firstOrFail();
    }

    private function link(Request $request, string $dependentId): Dependent
    {
        $link = Dependent::authorized()
            ->where('guardian_patient_profile_id', $this->guardian($request)->id)
            ->where('dependent_patient_profile_id', $dependentId)
            ->with('dependent')
            ->firstOrFail();

        Gate::authorize('view', $link->dependent);

        return $link;
    }
}
