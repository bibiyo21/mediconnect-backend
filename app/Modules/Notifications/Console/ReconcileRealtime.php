<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Services\RealtimeReconciler;
use Illuminate\Console\Command;

/** Closes sockets whose authorization or credential ended without a terminate call (increment 5.6-0). */
final class ReconcileRealtime extends Command
{
    protected $signature = 'realtime:reconcile';

    protected $description = 'Re-check every live realtime subscription and terminate connections that are no longer authorized';

    public function handle(RealtimeReconciler $reconciler): int
    {
        $stats = $reconciler->run();
        $this->info('realtime:reconcile '.json_encode($stats));

        return self::SUCCESS;
    }
}
