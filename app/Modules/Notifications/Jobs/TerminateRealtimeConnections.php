<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Services\RealtimeSessions;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Calls Reverb's signed HTTP API (POST /apps/{app}/users/{userId}/terminate_connections) through the
 * configured Pusher client. Idempotent: terminating a user with no connections does nothing, so a
 * retry is harmless. A failure is retried with backoff and, after the last try, logged and recorded as a security event (ids only).
 */
final class TerminateRealtimeConnections implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** Seconds. Above the Pusher client's HTTP timeout (3 s, config/broadcasting.php), so the client gives up first. */
    public int $timeout = 10;

    public function __construct(public readonly string $userId, public readonly string $reason)
    {
        $this->onQueue('critical'); // an authorization that ended must cut the socket promptly (CLAUDE.md §24)
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [2, 10, 30];
    }

    public function handle(BroadcastManager $broadcasting): void
    {
        $broadcaster = $broadcasting->connection();
        if (! $broadcaster instanceof PusherBroadcaster) {
            return; // null and log drivers hold no connections
        }

        $broadcaster->getPusher()->terminateUserConnections($this->userId);
    }

    public function failed(Throwable $e): void
    {
        RealtimeSessions::recordFailure($this->userId, $this->reason, $e);
    }
}
