<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Administration\Contracts\Settings;
use App\Modules\Administration\Http\Requests\UpdateSettingRequest;
use App\Shared\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** /admin/settings: platform settings (never secrets; Settings refuses secret-like keys). */
final class AdminSettingsController
{
    public function index(): JsonResponse
    {
        $rows = DB::table('system_settings')->orderBy('key')->get()->map(fn (object $row) => [
            'key' => (string) $row->key,
            'value' => json_decode((string) $row->value, true),
            'updated_by' => $row->updated_by,
            'updated_at' => $row->updated_at !== null ? CarbonImmutable::parse((string) $row->updated_at)->toIso8601ZuluString() : null,
        ]);

        return ApiResponse::success($rows->all());
    }

    public function update(UpdateSettingRequest $request, Settings $settings, string $settingKey): JsonResponse
    {
        $settings->set($settingKey, $request->input('value'));

        return ApiResponse::success(['key' => $settingKey, 'value' => $settings->get($settingKey)], message: 'Setting saved.');
    }
}
