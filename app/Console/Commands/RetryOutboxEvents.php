<?php

namespace App\Console\Commands;

use App\Shared\Services\Outbox;
use Illuminate\Console\Command;

/** Releases parked (dead-lettered) outbox events after the cause is fixed. */
final class RetryOutboxEvents extends Command
{
    protected $signature = 'outbox:retry {event_id? : Only this event (default: all parked events)}';

    protected $description = 'Retry outbox events that were parked after repeated failures';

    public function handle(Outbox $outbox): int
    {
        $released = $outbox->retryFailed($this->argument('event_id'));
        $this->info("Released {$released} parked event(s).");

        return self::SUCCESS;
    }
}
