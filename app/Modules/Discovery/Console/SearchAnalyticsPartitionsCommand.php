<?php

namespace App\Modules\Discovery\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prepares upcoming monthly search_analytics partitions and drops partitions
 * older than the 13-month retention (database-schema.md §18). Runs as the
 * schema owner; scheduled daily and safe to run at any time.
 */
final class SearchAnalyticsPartitionsCommand extends Command
{
    protected $signature = 'search:partitions {--months=3 : Months ahead to prepare} {--retain=13 : Months to keep}';

    protected $description = 'Create upcoming search_analytics partitions and drop expired ones';

    public function handle(): int
    {
        $owner = DB::connection('pgsql_owner');
        $created = (int) $owner->scalar('SELECT ensure_monthly_partitions(?, ?)', ['search_analytics', (int) $this->option('months')]);
        $cutoff = now()->startOfMonth()->subMonths((int) $this->option('retain'))->format('Y_m');
        $dropped = 0;
        foreach ($owner->select("SELECT inhrelid::regclass::text AS name FROM pg_inherits WHERE inhparent = 'search_analytics'::regclass") as $row) {
            if (preg_match('/^search_analytics_p(\d{4}_\d{2})$/', (string) $row->name, $m) === 1 && $m[1] < $cutoff) {
                $owner->statement('DROP TABLE '.$row->name);
                $dropped++;
            }
        }
        $this->info("Created {$created}, dropped {$dropped} partition(s).");

        return self::SUCCESS;
    }
}
