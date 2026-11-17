<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Fresh MFA verification for sensitive actions (rbac-permissions.md §7).
 * Bound to the current token or session, so one device's step-up does not
 * unlock another device.
 */
final class StepUp
{
    public function record(Request $request, User $user): string
    {
        $expiresAt = now()->addMinutes((int) config('authentication.mfa.step_up_minutes'));
        Cache::put($this->key($request, $user), true, $expiresAt);

        return $expiresAt->toIso8601ZuluString();
    }

    public function isFresh(Request $request, User $user): bool
    {
        return Cache::get($this->key($request, $user)) === true;
    }

    private function key(Request $request, User $user): string
    {
        $token = $user->currentAccessToken();
        $scope = $token instanceof PersonalAccessToken
            ? 'token:'.$token->getKey()
            : 'session:'.($request->hasSession() ? $request->session()->getId() : 'none');

        return 'stepup:'.$user->id.':'.$scope;
    }
}
