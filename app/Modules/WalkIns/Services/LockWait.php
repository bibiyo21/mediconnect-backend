<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\WalkIns\Exceptions\ConcurrentRegistration;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Shared\Database\LockTimeoutTransaction;
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

    /** One call at a time per doctor, across queues (5.6 D6): after the presence session lock, before any queue lock, call paths only. */
    public const DOCTOR_CALL = 5504;
    // Order on the call paths: presence session row FOR SHARE first, then this advisory lock, then the queue row. A holder of 5504 never
    // needs the presence row after taking it (it already holds it FOR SHARE, which check-out's FOR UPDATE waits on), and nothing takes 5504
    // while holding a queue or entry lock, so no cycle can form: a check-out waits for the call to finish, and a call never waits for a check-out holder
    // while holding 5504.

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        try {
            return LockTimeoutTransaction::run((int) config('walkins.lock_timeout_ms'), $work);
        } catch (QueryException $e) {
            throw $e->getCode() === '55P03' ? new ConcurrentRegistration : $e;
        }
    }

    /**
     * Call after the queue and entry locks: an entry that a transfer moved to another queue meanwhile is not in the queue this change locked.
     * The change must not write (the event trigger would refuse it), so the caller gets 409 CONCURRENT_MODIFICATION and retries under the new queue.
     */
    public static function assertEntryInQueue(WalkInQueueEntry $entry, WalkInQueue $locked): void
    {
        if ((string) $entry->walkin_queue_id !== (string) $locked->id) {
            throw new ConcurrentRegistration;
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
