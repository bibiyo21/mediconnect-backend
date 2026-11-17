<?php

namespace App\Modules\Authentication\Http\Resources;

use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Authentication\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserDevice */
class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var UserDevice $device */
        $device = $this->resource;
        $token = $request->user()?->currentAccessToken();
        $currentId = $token instanceof PersonalAccessToken ? $token->user_device_id : ($request->hasSession() ? $request->session()->get('auth.device_id') : null);

        return [
            'id' => $device->id,
            'platform' => $device->platform->value,
            'device_name' => $device->device_name,
            'app_version' => $device->app_version,
            'last_seen_at' => $device->last_seen_at?->toIso8601ZuluString(),
            'created_at' => $device->created_at?->toIso8601ZuluString(),
            'current' => $currentId === $device->id,
        ];
    }
}
