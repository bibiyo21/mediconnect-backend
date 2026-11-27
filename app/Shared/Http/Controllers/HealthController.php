<?php

namespace App\Shared\Http\Controllers;

use App\Shared\Contracts\SearchEngine;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * GET /health  — liveness: the process answers. No dependency details, no auth.
 * GET /ready   — readiness: PostgreSQL, Redis and migrations are usable.
 *                Search is reported but does not fail readiness: search has
 *                a PostgreSQL fallback (architecture.md §12.6).
 *                Internal networks only (config/health.php), because component
 *                status helps an attacker; anyone else gets 404.
 */
final class HealthController
{
    public function health(): JsonResponse
    {
        return ApiResponse::success(['status' => 'ok']);
    }

    public function ready(Request $request, Migrator $migrator, SearchEngine $search): JsonResponse
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
        $checks['search'] = $search->healthy() ? 'ok' : 'degraded';
        if (in_array((string) config('broadcasting.default'), ['reverb', 'pusher'], true)) {
            $checks['realtime_sweep'] = $this->sweepState(); // non-fatal, like search
        }

        return ApiResponse::success(['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks], status: $ready ? 200 : 503);
    }

    /**
     * `realtime:reconcile` state (written by RealtimeReconciler): ok (last clean run is recent), degraded (it
     * runs but skipped or could not cover something), stale (it has stopped) or unknown (never ran since the
     * cache was cleared). Cache stores such as Redis return numbers as strings, hence is_numeric.
     */
    private function sweepState(): string
    {
        try {
            $lastOk = Cache::get('realtime:sweep:last_ok');
            $lastRun = Cache::get('realtime:sweep:last_run');
        } catch (Throwable) {
            return 'unknown';
        }
        $limit = (int) config('realtime.reconcile.stale_after_seconds');
        $fresh = fn (mixed $v): bool => is_numeric($v) && time() - (int) $v <= $limit;

        return match (true) {
            $fresh($lastOk) => 'ok',
            $fresh($lastRun) => 'degraded',
            is_numeric($lastOk) || is_numeric($lastRun) => 'stale',
            default => 'unknown',
        };
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
