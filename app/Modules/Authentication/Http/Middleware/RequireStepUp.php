<?php

namespace App\Modules\Authentication\Http\Middleware;

use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Services\StepUp;
use App\Modules\Users\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the sensitive actions in rbac-permissions.md §7: requires a fresh MFA
 * verification on this token or session (POST /auth/mfa/step-up).
 */
final class RequireStepUp
{
    public function __construct(private readonly StepUp $stepUp) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->stepUp->isFresh($request, $user)) {
            throw AuthFailure::stepUpRequired();
        }

        return $next($request);
    }
}
