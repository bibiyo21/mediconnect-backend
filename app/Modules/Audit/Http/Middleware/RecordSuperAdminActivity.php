<?php

namespace App\Modules\Audit\Http\Middleware;

use App\Modules\Users\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

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
            DB::table('admin_activity_logs')->insert([
                'admin_user_id' => $user->id,
                'action' => (string) ($request->route()?->getName() ?? $request->route()?->uri() ?? 'unknown'),
                'method' => $request->method(),
                'path' => mb_substr('/'.$request->path(), 0, 255),
                'status_code' => $response->getStatusCode(),
                'step_up_verified' => false,
                'request_id' => Context::get('request_id'),
                'occurred_at' => now(),
            ]);
        }

        return $response;
    }
}
