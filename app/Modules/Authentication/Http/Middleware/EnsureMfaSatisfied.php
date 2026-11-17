<?php

namespace App\Modules\Authentication\Http\Middleware;

use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Users\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks sign-ins that still owe MFA enrollment (privileged roles,
 * rbac-permissions.md §2). Such sign-ins may only reach MFA setup and logout.
 */
final class EnsureMfaSatisfied
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        $satisfied = $token instanceof PersonalAccessToken
            ? $token->can('*')
            : $request->hasSession() && $request->session()->get('auth.mfa_satisfied') === true;

        if (! $satisfied) {
            throw AuthFailure::mfaRequired();
        }

        return $next($request);
    }
}
