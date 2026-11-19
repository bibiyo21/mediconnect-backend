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

        // Every change by a SUPER_ADMIN, and every change made through the admin API by anyone (api-specification.md §2.15).
        $isAdminApi = str_starts_with($request->path(), 'api/v1/admin/');

        if ($user instanceof User && ! in_array($request->method(), self::READ_METHODS, true) && ($isAdminApi || $user->hasRole('SUPER_ADMIN'))) {
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
            'target_type' => $this->routeTarget($request)[0],
            'target_id' => $this->routeTarget($request)[1],
            // The reason an admin gave for a sensitive action (step-up actions require one).
            'reason' => is_string($request->input('reason')) ? mb_substr($request->input('reason'), 0, 1000) : null,
            // Set by the step-up middleware (Authentication) when the request passed a fresh step-up.
            'step_up_verified' => $request->attributes->get(RequestAttributes::STEP_UP_VERIFIED) === true,
            'request_id' => Context::get('request_id'),
            'occurred_at' => now(),
        ]);
    }

    /** Route parameter that names the target, and its type. The first present one wins. */
    private const TARGETS = ['userId' => 'user', 'settingKey' => 'system_setting', 'doctorId' => 'doctor_profile', 'clinicId' => 'clinic', 'specialtyId' => 'specialty'];

    /** @return array{0: string|null, 1: string|null} */
    private function routeTarget(Request $request): array
    {
        foreach (self::TARGETS as $parameter => $type) {
            $value = $request->route($parameter);
            if (is_string($value)) {
                return [$type, mb_substr($value, 0, 64)];
            }
        }

        return [null, null];
    }
}
