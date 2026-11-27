<?php

namespace App\Modules\Authentication\Http\Requests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Device description sent by native clients when signing in. Web clients (cookie path) are recorded
 * as WEB automatically. A token (native) sign-in must say IOS or ANDROID: a token device is never
 * recorded as WEB, because the realtime sweep treats WEB devices as browser sessions.
 */
final class DeviceRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'device' => ['nullable', 'array'],
            'device.platform' => [Rule::requiredIf(fn () => ! EnsureFrontendRequestsAreStateful::fromFrontend(RequestFacade::instance())), 'nullable', 'string', 'in:IOS,ANDROID'],
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
