<?php

namespace App\Shared\Database;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Runs a unit of work in one transaction that waits only briefly for competing row locks: a wait past the timeout ends with
 * SQLSTATE 55P03, which the caller maps to its own 409 (decision 168 for walk-ins, D-P for consultations). Inside someone else's
 * transaction SET LOCAL would outlive this call, so the setting in force is put back afterwards. Shared kernel: no module code here.
 */
final class LockTimeoutTransaction
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws QueryException the database error, unmapped (the caller checks for 55P03)
     */
    public static function run(int $timeoutMs, Closure $work): mixed
    {
        $outer = DB::transactionLevel() > 0 ? (string) DB::selectOne("SELECT current_setting('lock_timeout') AS value")?->value : null;

        try {
            return DB::transaction(function () use ($timeoutMs, $work) {
                $ms = max(100, min(10000, $timeoutMs));
                DB::statement("SET LOCAL lock_timeout = '{$ms}ms'");

                return $work();
            });
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
}
