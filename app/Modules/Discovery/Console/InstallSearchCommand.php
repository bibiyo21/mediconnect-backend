<?php

namespace App\Modules\Discovery\Console;

use App\Modules\Discovery\Services\SearchCollections;
use App\Shared\Contracts\SearchEngine;
use Illuminate\Console\Command;

/** Creates any missing search collections behind their aliases. Safe to run repeatedly. */
final class InstallSearchCommand extends Command
{
    protected $signature = 'search:install';

    protected $description = 'Create missing search collections (empty) behind their aliases';

    public function handle(SearchCollections $collections, SearchEngine $engine): int
    {
        if (! $engine->healthy()) {
            $this->error('The search engine is not reachable.');

            return self::FAILURE;
        }
        $created = $collections->install();
        $this->info($created === [] ? 'All search collections already exist.' : 'Created: '.implode(', ', $created));

        return self::SUCCESS;
    }
}
