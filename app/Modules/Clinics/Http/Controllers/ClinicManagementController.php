<?php

namespace App\Modules\Clinics\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Http\Requests\UpdateClinicRequest;
use App\Modules\Clinics\Http\Resources\ClinicManagementResource;
use App\Modules\Clinics\Http\Resources\ClinicSummaryResource;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Services\ClinicGuard;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/clinics, GET /clinics/{id}/management, PATCH /clinics/{id}.
 * Clinic-scoped: global roles act anywhere; clinic roles only where they are members.
 */
final class ClinicManagementController
{
    public function __construct(
        private readonly ClinicGuard $guard,
        private readonly RecordsAudit $audit,
    ) {}

    public function mine(Request $request, ClinicAccess $access): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $clinics = Clinic::with(['organization', 'locations'])->withCount('locations')->whereIn('id', $access->memberClinicIds($user))->orderBy('name')->get();

        return ApiResponse::success(ClinicSummaryResource::collection($clinics));
    }

    public function show(Request $request, string $clinicId): JsonResponse
    {
        $clinic = $this->guard->authorize($request, 'clinics.view', $clinicId);

        return ApiResponse::success(new ClinicManagementResource(self::loaded($clinic), $this->guard->abilities($request, $clinic->id)));
    }

    public function update(UpdateClinicRequest $request, string $clinicId): JsonResponse
    {
        $clinic = $this->guard->authorizeWide($request, 'clinics.update', $clinicId);
        $clinic->fill($request->safe()->only(['name', 'description']));
        if ($request->has('status')) {
            $clinic->status = (string) $request->input('status');
        }
        $changed = array_keys($clinic->getDirty());
        $clinic->save();
        if ($changed !== []) {
            $this->audit->record('clinic.updated', 'clinic', $clinic->id, ['fields' => $changed], clinicId: $clinic->id);
        }

        return ApiResponse::success(new ClinicManagementResource(self::loaded($clinic), $this->guard->abilities($request, $clinic->id)));
    }

    public static function loaded(Clinic $clinic): Clinic
    {
        return $clinic->load(['organization', 'locations.operatingHours', 'locations.services', 'holidays', 'members.user.profile']);
    }
}
