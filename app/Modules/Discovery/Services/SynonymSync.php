<?php

namespace App\Modules\Discovery\Services;

use App\Modules\Discovery\Models\SearchSynonym;
use App\Shared\Contracts\SearchEngine;
use App\Shared\Search\SearchUnavailable;

/**
 * Pushes the ACTIVE synonyms to the engine's synonym set and invalidates
 * cached results. PostgreSQL holds the list; the engine copy can always be
 * rebuilt from it (also on every search:install and search:rebuild).
 */
final class SynonymSync
{
    public function __construct(private readonly SearchEngine $engine) {}

    /** @return list<array{id: string, synonyms: list<string>, root?: string}> */
    public static function items(): array
    {
        return array_values(SearchSynonym::where('status', 'ACTIVE')->orderBy('created_at')->get()->map(function (SearchSynonym $s) {
            $item = ['id' => $s->id, 'synonyms' => array_map('mb_strtolower', $s->synonyms)];
            if ($s->root_term !== null) {
                $item['root'] = mb_strtolower($s->root_term);
            }

            return $item;
        })->all());
    }

    /** @return bool false when the engine was unreachable (the next install or rebuild pushes the list) */
    public function push(): bool
    {
        try {
            $this->engine->putSynonymSet((string) config('search.synonym_set'), self::items());
        } catch (SearchUnavailable $e) {
            report($e);

            return false;
        }
        foreach (['doctors', 'clinics', 'specialties'] as $collection) {
            SearchCache::bump($collection);
        }

        return true;
    }
}
