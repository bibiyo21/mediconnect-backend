<?php

use App\Shared\Contracts\SearchEngine;
use App\Shared\Search\TypesenseEngine;
use Typesense\Client;

/** The local Typesense, or null where it is not reachable (CI): live search tests skip then. */
function live_engine(): ?SearchEngine
{
    $c = (array) config('search.typesense');
    if (empty($c['api_key'])) {
        return null;
    }
    $node = ['host' => (string) $c['host'], 'port' => (string) $c['port'], 'protocol' => (string) $c['protocol']];
    $engine = new TypesenseEngine(new Client(['api_key' => (string) $c['api_key'], 'nodes' => [$node], 'connection_timeout_seconds' => 1, 'num_retries' => 0]), $node['protocol'].'://'.$node['host'].':'.$node['port'], (string) $c['api_key']);

    return $engine->healthy() ? $engine : null;
}
