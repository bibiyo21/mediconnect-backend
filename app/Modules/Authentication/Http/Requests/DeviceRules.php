<?php

namespace App\Modules\Authentication\Http\Requests;

use Illuminate\Http\Request;

/**
 * Device description sent by native clients when signing in. Web clients
 * are recorded as WEB automatically.
 */
final class DeviceRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'device' => ['nullable', 'array'],
            'device.platform' => ['nullable', 'string', 'in:IOS,ANDROID'],
            'device.name' => ['nullable', 'string', 'max:100'],
            'device.app_version' => ['nullable', 'string', 'max:40'],
        ];
    }

    /** @return array{platform: string|null, name: string|null, app_version: string|null} */
    public static function from(Request $request): array
    {
        return [
            'platform' => $request->input('device.platform'),
            'name' => $request->input('device.name'),
            'app_version' => $request->input('device.app_version'),
        ];
    }
}
