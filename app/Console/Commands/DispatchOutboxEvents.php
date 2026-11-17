<?php

namespace App\Console\Commands;

use App\Shared\Services\Outbox;
use Illuminate\Console\Command;

/** Scheduler safety net for the transactional outbox (runs every minute). */
final class DispatchOutboxEvents extends Command
{
    protected $signature = 'outbox:dispatch';

    protected $description = 'Relay pending outbox events to their listeners';

    public function handle(Outbox $outbox): int
    {
        $this->info('Dispatched '.$outbox->relay().' event(s).');

        return self::SUCCESS;
    }
}
