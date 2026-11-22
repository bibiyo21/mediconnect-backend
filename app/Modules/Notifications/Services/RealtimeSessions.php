<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Notifications\Contracts\TerminatesRealtimeSessions;
use App\Modules\Notifications\Jobs\TerminateRealtimeConnections;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues the termination of a user's WebSocket connections after the surrounding transaction
 * commits (`DB::afterCommit` runs at once outside a transaction and drops the callback on a
 * rollback). The null and log broadcast drivers have no connections, so they do nothing.
 *
 * The termination goes through Reverb's `terminate_connections`, which closes the connections that
 * carry this user id. Reverb records a user id only from a presence channel's channel_data, which is
 * why every channel is a presence channel (see Channels).
 *
 * A failure never breaks the request that ended the authorization, and it is never silent: it is
 * logged by id and recorded as a REALTIME_TERMINATION_FAILED security event (HIGH), so a socket that
 * could not be cut is visible to operations. The job runs on the `critical` queue (CLAUDE.md §24) and
 * the Pusher client's HTTP timeout (config/broadcasting.php, 3 s) is below the job timeout.
 *
 * Residual risk, by design of Reverb (no per-socket close API exists): a socket that obtained a
 * channel signature from /broadcasting/auth but has NOT yet subscribed carries no user id, so
 * `terminate_connections` cannot reach it, and a Pusher signature is bound to the socket id but never
 * expires. Such a socket could subscribe after the termination. It would still receive only hints
 * (ids, counts, queue numbers; see RealtimeEvent), it cannot call any API with them, and it is
 * closed by the next termination for that user. Remembering issued socket ids would not help
 * because nothing can close a socket by id.
 */
final class RealtimeSessions implements TerminatesRealtimeSessions
{
    /** Broadcast drivers that speak the Pusher protocol and so hold connections. */
    private const CONNECTION_DRIVERS = ['reverb', 'pusher'];

    public function terminateForUser(string $userId, string $reason): void
    {
        if (! in_array((string) config('broadcasting.default'), self::CONNECTION_DRIVERS, true)) {
            return;
        }

        try {
            DB::afterCommit(function () use ($userId, $reason) {
                try {
                    TerminateRealtimeConnections::dispatch($userId, $reason);
                } catch (Throwable $e) {
                    self::recordFailure($userId, $reason, $e);
                }
            });
        } catch (Throwable $e) {
            self::recordFailure($userId, $reason, $e);
        }
    }

    /**
     * Logs by id and records a HIGH security event: ids, the reason label and the exception class
     * only, never the message (it can echo a host or a secret). One row per user and reason a minute,
     * so a retry storm or the sync queue does not repeat it.
     */
    public static function recordFailure(string $userId, string $reason, Throwable $e): void
    {
        Log::warning('realtime.terminate_failed', ['user_id' => $userId, 'reason' => $reason, 'exception' => $e::class]);

        try {
            if (Cache::add('realtime-terminate-failed:'.hash('sha256', $userId.'|'.$reason), 1, 60)) {
                app(RecordsSecurityEvents::class)->record(SecurityEventType::RealtimeTerminationFailed, $userId, ['reason' => $reason, 'exception' => $e::class]);
            }
        } catch (Throwable) {
            // The log line above is the fallback; recording must never break the caller.
        }
    }
}
