<?php

namespace App\Modules\Audit\Http\Middleware;

use App\Modules\Users\Models\User;
use App\Shared\Http\RequestAttributes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * "Super Admin actions must be audited" (§20.3): every state-changing API
 * request by a SUPER_ADMIN is written to admin_activity_logs, whatever the
 * outcome. Sensitive reads are audited by the resources themselves.
 */
final class RecordSuperAdminActivity
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User && ! in_array($request->method(), self::READ_METHODS, true) && $user->hasRole('SUPER_ADMIN')) {
            try {
                $this->write($request, $user, $response);
            } catch (Throwable $e) {
                // The action already happened: never turn its result into an error. Report loudly instead.
                report($e);
            }
        }

        return $response;
    }

    private function write(Request $request, User $user, Response $response): void
    {
        DB::table('admin_activity_logs')->insert([
            'admin_user_id' => $user->id,
            'action' => (string) ($request->route()?->getName() ?? $request->route()?->uri() ?? 'unknown'),
            'method' => $request->method(),
            'path' => mb_substr('/'.$request->path(), 0, 255),
            'status_code' => $response->getStatusCode(),
            // Set by the step-up middleware (Authentication) when the request passed a fresh step-up.
            'step_up_verified' => $request->attributes->get(RequestAttributes::STEP_UP_VERIFIED) === true,
            'request_id' => Context::get('request_id'),
            'occurred_at' => now(),
        ]);
    }
}
