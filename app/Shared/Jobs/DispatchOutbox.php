<?php

namespace App\Shared\Jobs;

use App\Shared\Services\Outbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Relays outbox events soon after a commit. Unique only while waiting in the
 * queue: a burst of commits schedules one relay, and the lock is released as
 * soon as the relay starts, so an event committed during a run gets a new
 * relay instead of waiting for the scheduler.
 */
final class DispatchOutbox implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 30;

    public function __construct()
    {
        $this->onQueue('critical');
    }

    public function handle(Outbox $outbox): void
    {
        $outbox->relay();
    }
}
