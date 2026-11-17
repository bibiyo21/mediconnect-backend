<?php

namespace App\Shared\Jobs;

use App\Shared\Services\Outbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Relays outbox events soon after a commit. Unique while queued: a burst of
 * commits schedules one relay, not hundreds.
 */
final class DispatchOutbox implements ShouldBeUnique, ShouldQueue
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
