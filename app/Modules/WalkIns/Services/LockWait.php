<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\WalkIns\Exceptions\ConcurrentRegistration;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Runs a registration or queue change in one transaction that waits only briefly for competing locks (decision 168): a wait past
 * walkins.lock_timeout_ms ends with SQLSTATE 55P03, answered as 409 CONCURRENT_MODIFICATION instead of hanging. Inside someone
 * else's transaction (a test, a caller's unit of work) SET LOCAL would outlive this call, so the setting in force is put back.
 */
final class LockWait
{
    public const ACCOUNT = 5501;

    public const PATIENT = 5502;

    public const LOCATION_DAY = 5503;

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        $outer = DB::transactionLevel() > 0 ? (string) DB::selectOne("SELECT current_setting('lock_timeout') AS value")?->value : null;

        try {
            return DB::transaction(function () use ($work) {
                $ms = max(100, min(10000, (int) config('walkins.lock_timeout_ms')));
                DB::statement("SET LOCAL lock_timeout = '{$ms}ms'");

                return $work();
            });
        } catch (QueryException $e) {
            throw $e->getCode() === '55P03' ? new ConcurrentRegistration : $e;
        } finally {
            if ($outer !== null) {
                try {
                    DB::statement("SET LOCAL lock_timeout = '".str_replace("'", '', $outer)."'");
                } catch (\Throwable) {
                    // The outer transaction is unusable anyway; nothing to restore.
                }
            }
        }
    }

    /**
     * A transaction-scoped advisory lock (decision 96): taken before any row lock, on the remote path only, so two requests
     * of one account or for one patient run one after the other. Nothing else takes these locks while holding row locks, so
     * taking them first cannot deadlock.
     */
    public static function advisory(int $namespace, string $key): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?, hashtext(?))', [$namespace, $key]);
    }
}
