<?php

namespace App\Shared\Http\Controllers;

use App\Shared\Http\ApiResponse;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * GET /health  — liveness: the process answers. No dependency details, no auth.
 * GET /ready   — readiness: PostgreSQL, Redis and migrations are usable.
 *                Internal networks only (config/health.php), because component
 *                status helps an attacker; anyone else gets 404.
 */
final class HealthController
{
    public function health(): JsonResponse
    {
        return ApiResponse::success(['status' => 'ok']);
    }

    public function ready(Request $request, Migrator $migrator): JsonResponse
    {
        /** @var list<string> $allowed */
        $allowed = config('health.ready_allowed_cidrs');

        if (! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            abort(404);
        }

        $checks = [
            'database' => $this->check('database', fn () => DB::select('select 1')),
            'redis' => $this->check('redis', fn () => Redis::connection()->ping()),
            'migrations' => $this->check('migrations', function () use ($migrator) {
                $ran = $migrator->getRepository()->getRan();
                // array_merge, not +: package paths are a list, and + would drop our own folder.
                $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));

                if (array_diff($files, $ran) !== []) {
                    throw new \RuntimeException('pending migrations');
                }
            }),
        ];

        $ready = ! in_array('fail', $checks, true);

        return ApiResponse::success(['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks], status: $ready ? 200 : 503);
    }

    private function check(string $name, callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            // Warning, not report(): load balancers probe every few seconds, and
            // the 503 itself is the alert. Reporting each failure would flood Sentry.
            Log::warning('Readiness check failed', ['check' => $name, 'error' => $e::class]);

            return 'fail';
        }
    }
}
