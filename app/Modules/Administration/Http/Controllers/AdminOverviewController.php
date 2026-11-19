<?php

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** GET /admin/overview: counts only, no personal data. */
final class AdminOverviewController
{
    public function __invoke(): JsonResponse
    {
        $byStatus = User::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ApiResponse::success([
            'users' => [
                'total' => (int) $byStatus->sum(),
                'active' => (int) ($byStatus[UserStatus::Active->value] ?? 0),
                'suspended' => (int) ($byStatus[UserStatus::Suspended->value] ?? 0),
                'pending_deletion' => User::whereNotNull('deletion_requested_at')->whereNull('anonymized_at')->count(),
            ],
            'patients' => DB::table('patient_profiles')->whereNull('deleted_at')->count(),
            'dependents' => DB::table('dependents')->where('authorization_status', 'AUTHORIZED')->count(),
            'security_events_24h' => DB::table('security_events')->where('occurred_at', '>=', now()->subDay())->count(),
        ]);
    }
}
