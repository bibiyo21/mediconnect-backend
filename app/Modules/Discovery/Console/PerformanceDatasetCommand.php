<?php

namespace App\Modules\Discovery\Console;

use Database\Seeders\PerformanceDatasetSeeder;
use Illuminate\Console\Command;

/**
 * Loads or removes the synthetic performance dataset (increment 3.7). LOCAL only:
 * the seeder refuses every other environment. Afterwards run `search:rebuild all`
 * so the index matches PostgreSQL again.
 */
final class PerformanceDatasetCommand extends Command
{
    protected $signature = 'perf:dataset {--remove : Delete the dataset instead of loading it} {--doctors=10000} {--locations=2000}';

    protected $description = 'Load (or remove) the synthetic search performance dataset on LOCAL';

    public function handle(): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('The performance dataset is for LOCAL only.');

            return self::FAILURE;
        }
        if ($this->option('remove')) {
            $this->info('Removed '.PerformanceDatasetSeeder::remove().' synthetic doctors. Now run: php artisan search:rebuild all');

            return self::SUCCESS;
        }
        if (PerformanceDatasetSeeder::present()) {
            $this->error('The performance dataset is already loaded. Remove it first with --remove.');

            return self::FAILURE;
        }
        $started = microtime(true);
        (new PerformanceDatasetSeeder((int) $this->option('doctors'), (int) $this->option('locations')))->run();
        $this->info(sprintf('Loaded %d synthetic doctors across %d locations in %.1f s. Now run: php artisan search:rebuild all', (int) $this->option('doctors'), (int) $this->option('locations'), microtime(true) - $started));

        return self::SUCCESS;
    }
}
