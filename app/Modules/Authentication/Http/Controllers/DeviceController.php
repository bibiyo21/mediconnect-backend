<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Authentication\Http\Resources\DeviceResource;
use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Authentication\Services\DeviceRevocation;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiExceptionRenderer;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /auth/devices, DELETE /auth/devices/{id}: the user's own devices only. */
final class DeviceController
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $devices = UserDevice::where('user_id', $user->id)->whereNull('revoked_at')->latest('last_seen_at')->get();

        return ApiResponse::success(DeviceResource::collection($devices)->resolve($request));
    }

    public function destroy(Request $request, string $deviceId, DeviceRevocation $revocation, RecordsSecurityEvents $events): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Scoped to the owner: another user's device id gives the same 404 as a missing one.
        $device = UserDevice::where('user_id', $user->id)->whereNull('revoked_at')->whereKey($deviceId)->first();

        if ($device === null) {
            return ApiExceptionRenderer::render(new ModelNotFoundException);
        }

        $revocation->revokeDevice($device);
        $events->record(SecurityEventType::DeviceRevoked, $user->id, ['device_id' => $device->id]);

        return ApiResponse::success(message: 'The device was signed out.');
    }
}
