<?php

namespace App\Modules\Discovery\Services;

use App\Modules\Clinics\Models\Clinic;
use App\Modules\Specialties\Models\Specialty;
use App\Shared\Contracts\SearchEngine;
use App\Shared\Search\SearchUnavailable;

/** Clinic search and specialty autocomplete, with the same cache, breaker and fallback as doctor search. */
final class DirectorySearch
{
    public function __construct(
        private readonly SearchEngine $engine,
        private readonly SearchCollections $collections,
        private readonly SearchCache $cache,
        private readonly CircuitBreaker $breaker,
        private readonly DirectoryDocuments $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $c
     * @return array{results: list<array<string, mixed>>, found: int, page: int, per_page: int, degraded: bool, cache_hit: bool}
     */
    public function clinics(array $c): array
    {
        $q = QueryNormalizer::text($c['q'] ?? null);
        $page = max(1, (int) ($c['page'] ?? 1));
        $perPage = (int) ($c['per_page'] ?? 20);

        return $this->run('clinics', [...$c, 'q' => mb_strtolower($q), 'page' => $page], function () use ($q, $c, $page, $perPage) {
            $filters = [];
            if (isset($c['city'])) {
                $filters[] = 'city:='.QueryNormalizer::filterValue($c['city']);
            }
            if (isset($c['lat'], $c['lng'])) {
                $filters[] = sprintf('location:(%F, %F, %F km)', (float) $c['lat'], (float) $c['lng'], (float) ($c['radius_km'] ?? 10));
            }
            $params = ['q' => $q === '' ? '*' : $q, 'query_by' => 'name,location_name,services,hospital_name,barangay,city', 'query_by_weights' => '5,3,2,2,1,1', 'num_typos' => 2,
                'page' => $page, 'per_page' => $perPage, 'sort_by' => isset($c['lat'], $c['lng']) ? sprintf('location(%F, %F):asc', (float) $c['lat'], (float) $c['lng']) : ($q === '' ? 'doctor_count:desc' : '_text_match:desc,doctor_count:desc')];
            if ($filters !== []) {
                $params['filter_by'] = implode(' && ', $filters);
            }
            $raw = $this->engine->search($this->collections->alias('clinics'), $params);

            return ['results' => array_values(array_map(fn (array $h) => self::clinic([...(array) $h['document'], 'geo_distance_meters' => $h['geo_distance_meters']['location'] ?? null]), (array) ($raw['hits'] ?? []))), 'found' => (int) ($raw['found'] ?? 0), 'page' => $page, 'per_page' => $perPage];
        }, function () use ($q, $c, $page, $perPage) {
            $query = Clinic::query()->where('status', 'ACTIVE')
                ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->whereRaw('f_unaccent(lower(name)) LIKE f_unaccent(?)', ['%'.addcslashes(mb_strtolower($q), '%_\\').'%'])->orWhereRaw('similarity(f_unaccent(lower(name)), f_unaccent(?)) > 0.3', [mb_strtolower($q)])))
                ->when(isset($c['city']), fn ($b) => $b->whereHas('locations', fn ($l) => $l->where('status', 'ACTIVE')->whereRaw('lower(city) = lower(?)', [$c['city']])));
            $found = (clone $query)->count();
            $docs = [];
            foreach ($query->orderBy('name')->forPage($page, $perPage)->pluck('id') as $id) {
                array_push($docs, ...$this->documents->forClinic((string) $id));
            }

            return ['results' => array_map(fn (array $d) => self::clinic($d), $docs), 'found' => $found, 'page' => $page, 'per_page' => $perPage];
        });
    }

    /**
     * @return array{results: list<array<string, mixed>>, found: int, page: int, per_page: int, degraded: bool, cache_hit: bool}
     */
    public function specialties(?string $text, int $limit): array
    {
        $q = QueryNormalizer::text($text);

        return $this->run('specialties', ['q' => mb_strtolower($q), 'limit' => $limit], function () use ($q, $limit) {
            $raw = $this->engine->search($this->collections->alias('specialties'), ['q' => $q === '' ? '*' : $q, 'query_by' => 'name,synonyms,parent_name', 'query_by_weights' => '4,3,1', 'prefix' => true, 'num_typos' => 1, 'per_page' => $limit, 'sort_by' => $q === '' ? 'doctor_count:desc' : '_text_match:desc,doctor_count:desc']);

            return ['results' => array_values(array_map(fn (array $h) => self::specialty((array) $h['document']), (array) ($raw['hits'] ?? []))), 'found' => (int) ($raw['found'] ?? 0), 'page' => 1, 'per_page' => $limit];
        }, function () use ($q, $limit) {
            $prefix = addcslashes(mb_strtolower($q), '%_\\').'%';
            $rows = Specialty::where('is_active', true)
                ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->whereRaw('f_unaccent(lower(name)) LIKE f_unaccent(?)', [$prefix])->orWhereRaw('? = ANY (synonyms)', [mb_strtolower($q)])))
                ->orderBy('name')->limit($limit)->get();

            return ['results' => array_values($rows->map(fn (Specialty $s) => self::specialty(['specialty_id' => $s->id, 'id' => $s->id, 'name' => $s->name, 'kind' => 'SPECIALTY', 'doctor_count' => 0]))->all()), 'found' => $rows->count(), 'page' => 1, 'per_page' => $limit];
        });
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  callable(): array{results: list<array<string, mixed>>, found: int, page: int, per_page: int}  $engine
     * @param  callable(): array{results: list<array<string, mixed>>, found: int, page: int, per_page: int}  $fallback
     * @return array{results: list<array<string, mixed>>, found: int, page: int, per_page: int, degraded: bool, cache_hit: bool}
     */
    private function run(string $collection, array $key, callable $engine, callable $fallback): array
    {
        if (! $this->breaker->isOpen()) {
            try {
                [$result, $hit] = $this->cache->remember($collection, $key, $engine);

                return [...$result, 'degraded' => false, 'cache_hit' => $hit];
            } catch (SearchUnavailable $e) {
                report($e);
                $this->breaker->trip();
            }
        }

        return [...$fallback(), 'degraded' => true, 'cache_hit' => false];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function clinic(array $d): array
    {
        return [
            'clinic_id' => (string) $d['clinic_id'], 'clinic_location_id' => (string) $d['id'], 'name' => (string) $d['name'], 'location_name' => (string) $d['location_name'],
            'barangay' => $d['barangay'] ?? null, 'city' => (string) $d['city'], 'services' => array_values((array) ($d['services'] ?? [])), 'doctor_count' => (int) ($d['doctor_count'] ?? 0),
            'distance_km' => isset($d['geo_distance_meters']) ? round($d['geo_distance_meters'] / 1000, 1) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function specialty(array $d): array
    {
        return ['specialty_id' => (string) $d['specialty_id'], 'id' => (string) $d['id'], 'name' => (string) $d['name'], 'kind' => (string) $d['kind'], 'parent_name' => $d['parent_name'] ?? null, 'doctor_count' => (int) ($d['doctor_count'] ?? 0)];
    }
}
