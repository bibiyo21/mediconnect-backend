<?php

namespace App\Modules\Discovery\Console;

use App\Modules\Discovery\Services\SearchCollections;
use App\Shared\Contracts\SearchEngine;
use Illuminate\Console\Command;

/** Shows engine health and which collection each alias points to. */
final class SearchStatusCommand extends Command
{
    protected $signature = 'search:status';

    protected $description = 'Show search engine health and collection aliases';

    public function handle(SearchCollections $collections, SearchEngine $engine): int
    {
        $healthy = $engine->healthy();
        $this->line('Engine: '.($healthy ? 'healthy' : 'unreachable'));
        if (! $healthy) {
            return self::FAILURE;
        }
        $rows = array_map(fn (string $name) => [$name, $collections->alias($name), $engine->aliasTarget($collections->alias($name)) ?? '(missing)'], $collections->names());
        $this->table(['Collection', 'Alias', 'Points to'], $rows);

        return self::SUCCESS;
    }
}
