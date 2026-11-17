<?php

namespace App\Modules\Audit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates monthly audit_logs partitions ahead of time (scheduled monthly and
 * safe to run at any time). Runs as the schema owner: the application role
 * cannot create tables.
 */
final class CreateAuditPartitions extends Command
{
    protected $signature = 'audit:partitions {--months=3 : Months ahead to prepare}';

    protected $description = 'Create upcoming monthly partitions for audit_logs';

    public function handle(): int
    {
        $created = (int) DB::connection('pgsql_owner')->scalar('SELECT ensure_monthly_partitions(?, ?)', ['audit_logs', (int) $this->option('months')]);

        $inDefault = (int) DB::connection('pgsql_owner')->scalar('SELECT count(*) FROM audit_logs_default');

        $this->info("Created {$created} partition(s).");

        if ($inDefault > 0) {
            // Rows here mean the job fell behind; they must be moved before a partition for their month can be created.
            $this->warn("{$inDefault} audit row(s) are in audit_logs_default. See the operational runbook.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
