<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Deletes idempotency keys past their 48-hour window (hourly). */
final class PurgeIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:purge';

    protected $description = 'Delete expired idempotency keys';

    public function handle(): int
    {
        $deleted = DB::table('idempotency_keys')->where('expires_at', '<', now())->delete();
        $this->info("Deleted {$deleted} expired key(s).");

        return self::SUCCESS;
    }
}
