<?php

namespace App\Modules\WalkIns\Jobs;

use App\Modules\WalkIns\Realtime\QueueRealtime;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** The trailing edge of the queue-hint debounce: sends one WalkInQueueUpdated with the state at that moment. Idempotent; a hint only. */
final class EmitQueueUpdated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 10;

    public function __construct(public readonly string $queueId)
    {
        $this->onQueue('notifications');
    }

    public function handle(QueueRealtime $realtime): void
    {
        // A failed hint is dropped, not retried into failed_jobs (M-1): clients refetch.
        $realtime->safely($this->queueId, fn () => $realtime->emitQueueHint($this->queueId), 'EmitQueueUpdated');
    }
}
