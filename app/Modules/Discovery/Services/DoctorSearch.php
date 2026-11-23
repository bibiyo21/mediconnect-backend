<?php

namespace App\Modules\Discovery\Services;

use App\Modules\Doctors\Models\DoctorProfile;
use App\Shared\Contracts\SearchEngine;
use App\Shared\Search\SearchUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Doctor search (architecture.md §12): Typesense with typo tolerance,
 * synonyms, field weights and facets, grouped by doctor; cached by
 * generation; and a reduced PostgreSQL fallback, flagged `degraded`, when the
 * engine is unavailable. Results are advisory: booking revalidates (§11.6).
 */
final class DoctorSearch
{
    public function __construct(
        private readonly SearchEngine $engine,
        private readonly SearchCollections $collections,
        private readonly SearchCache $cache,
        private readonly CircuitBreaker $breaker,
        private readonly DoctorDocuments $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $criteria  validated SearchDoctorsRequest input
     * @return array{results: list<array<string, mixed>>, found: int, page: int, per_page: int, facets: array<string, array<string, int>>, degraded: bool, cache_hit: bool}
     */
    public function search(array $criteria): array
    {
        $criteria['q'] = QueryNormalizer::text($criteria['q'] ?? null);
        $page = max(1, (int) ($criteria['page'] ?? 1));
        $perPage = (int) ($criteria['per_page'] ?? 20);

        if (! $this->breaker->isOpen()) {
            try {
                [$result, $hit] = $this->cache->remember('doctors', [...$criteria, 'q' => mb_strtolower($criteria['q']), 'page' => $page, 'per_page' => $perPage], fn () => $this->fromEngine($criteria, $page, $perPage));

                return [...$result, 'degraded' => false, 'cache_hit' => $hit];
            } catch (SearchUnavailable $e) {
                report($e);
                $this->breaker->trip();
            }
        }

        return [...$this->fromDatabase($criteria, $page, $perPage), 'degraded' => true, 'cache_hit' => false];
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array{results: list<array<string, mixed>>, found: int, page: int, per_page: int, facets: array<string, array<string, int>>}
     */
    private function fromEngine(array $criteria, int $page, int $perPage): array
    {
        $q = $criteria['q'] === '' ? '*' : $criteria['q'];
        $params = [
            'q' => $q,
            'query_by' => implode(',', array_keys($fields = (array) config('search.ranking.doctor_fields'))),
            'query_by_weights' => implode(',', array_values($fields)),
            'prioritize_exact_match' => true,
            'prioritize_token_position' => true,
            'num_typos' => (int) config('search.ranking.num_typos', 2),
            'text_match_type' => 'max_weight',
            'group_by' => 'doctor_id',
            'group_limit' => 5,
            'page' => $page,
            'per_page' => $perPage,
            'facet_by' => 'specialties,city,consultation_modes,languages',
            'max_facet_values' => 20,
            'sort_by' => $this->sort($criteria, $q === '*'),
        ];
        $filter = $this->filter($criteria);
        if ($filter !== '') {
            $params['filter_by'] = $filter;
        }

        $raw = $this->engine->search($this->collections->alias('doctors'), $params);
        $results = [];
        foreach ((array) ($raw['grouped_hits'] ?? []) as $group) {
            $results[] = $this->summarise(array_values(array_map(fn (array $hit) => [...(array) $hit['document'], 'geo_distance_meters' => $hit['geo_distance_meters']['location'] ?? null], (array) $group['hits'])));
        }
        $facets = [];
        foreach ((array) ($raw['facet_counts'] ?? []) as $facet) {
            foreach ((array) $facet['counts'] as $count) {
                $facets[(string) $facet['field_name']][(string) $count['value']] = (int) $count['count'];
            }
        }

        return ['results' => $results, 'found' => (int) ($raw['found'] ?? count($results)), 'page' => $page, 'per_page' => $perPage, 'facets' => $facets];
    }

    /**
     * Reduced search on PostgreSQL (§12.6): prefix and trigram matching on the
     * indexed name and specialty columns, hard limits, no typo tolerance, no
     * facets or distance. Documents are built the same way as for the index.
     *
     * @param  array<string, mixed>  $criteria
     * @return array{results: list<array<string, mixed>>, found: int, page: int, per_page: int, facets: array<string, array<string, int>>}
     */
    private function fromDatabase(array $criteria, int $page, int $perPage): array
    {
        $q = mb_strtolower((string) $criteria['q']);
        $query = DoctorProfile::query()->where('onboarding_status', 'ACTIVE')
            ->when($q !== '', function (Builder $b) use ($q) {
                $prefix = addcslashes($q, '%_\\').'%';
                $b->where(function (Builder $w) use ($q, $prefix) {
                    $w->whereRaw('f_unaccent(lower(display_name)) LIKE f_unaccent(?)', [$prefix])
                        ->orWhereRaw('f_unaccent(lower(display_name)) LIKE f_unaccent(?)', ['%'.$prefix])
                        ->orWhereRaw('similarity(f_unaccent(lower(display_name)), f_unaccent(?)) > 0.3', [$q])
                        ->orWhereExists(fn ($s) => $s->from('doctor_specialties')->join('specialties', 'specialties.id', '=', 'doctor_specialties.specialty_id')
                            ->whereColumn('doctor_specialties.doctor_profile_id', 'doctor_profiles.id')->where('doctor_specialties.verification_status', 'CONFIRMED')
                            ->where(fn ($n) => $n->whereRaw('f_unaccent(lower(specialties.name)) LIKE f_unaccent(?)', [$prefix])->orWhereRaw('? = ANY (specialties.synonyms)', [$q])));
                });
            })
            ->when(isset($criteria['specialty']), fn (Builder $b) => $b->whereExists(fn ($s) => $s->from('doctor_specialties')->whereColumn('doctor_profile_id', 'doctor_profiles.id')->where('specialty_id', $criteria['specialty'])->where('verification_status', 'CONFIRMED')))
            ->when(isset($criteria['city']), fn (Builder $b) => $b->whereExists(fn ($s) => $s->from('doctor_clinic_assignments')->join('clinic_locations', 'clinic_locations.id', '=', 'doctor_clinic_assignments.clinic_location_id')
                ->whereColumn('doctor_clinic_assignments.doctor_profile_id', 'doctor_profiles.id')->where('doctor_clinic_assignments.status', 'ACTIVE')->whereRaw('lower(clinic_locations.city) = lower(?)', [$criteria['city']])))
            ->when(isset($criteria['consultation_type']), fn (Builder $b) => $b->whereExists(fn ($s) => $s->from('doctor_services')->whereColumn('doctor_services.doctor_profile_id', 'doctor_profiles.id')->where('doctor_services.status', 'ACTIVE')->where('consultation_type', $criteria['consultation_type'])));

        $this->availabilityFromDatabase($query, $criteria);
        $found = (clone $query)->count();
        if (($criteria['sort'] ?? null) === 'next_available') {
            // The application clock, bound like the availability filter, so both agree on "now" (not PostgreSQL's now()).
            $query->orderByRaw("(SELECT min(starts_at) FROM doctor_availability_slots a WHERE a.doctor_profile_id = doctor_profiles.id AND a.slot_kind = 'APPOINTMENT' AND a.status = 'OPEN' AND a.starts_at > ?) ASC NULLS LAST", [now()]);
        }
        $ids = $query->orderByDesc('profile_completeness')->orderBy('display_name')->forPage($page, $perPage)->pluck('id');
        $results = [];
        foreach ($ids as $id) {
            $docs = $this->documents->forDoctor((string) $id);
            if ($docs !== []) {
                $results[] = $this->summarise($docs);
            }
        }

        return ['results' => $results, 'found' => $found, 'page' => $page, 'per_page' => $perPage, 'facets' => []];
    }

    /**
     * Degraded-mode availability filter on the projection (4.8): open appointment
     * slots in the asked window, Philippine time. The booking policy is not applied
     * here; results are flagged degraded and a hold revalidates.
     *
     * @param  Builder<DoctorProfile>  $query
     * @param  array<string, mixed>  $c
     */
    private function availabilityFromDatabase(Builder $query, array $c): void
    {
        $date = $c['available_date'] ?? null;
        if ($date === null && ! isset($c['available_time_from']) && ! isset($c['available_time_to'])) {
            return;
        }
        $tz = (string) config('mediconnect.timezone');
        $from = (string) ($c['available_time_from'] ?? '00:00');
        $to = (string) ($c['available_time_to'] ?? '24:00');
        $query->whereExists(function ($s) use ($date, $from, $to, $tz) {
            $s->from('doctor_availability_slots as a')->whereColumn('a.doctor_profile_id', 'doctor_profiles.id')
                ->where('a.slot_kind', 'APPOINTMENT')->where('a.status', 'OPEN')->where('a.starts_at', '>', now());
            if ($date !== null) {
                $s->where('a.local_date', $date);
            }
            $s->whereRaw('(a.starts_at AT TIME ZONE ?)::time >= ?::time', [$tz, $from]);
            if ($to !== '24:00') {
                $s->whereRaw('(a.starts_at AT TIME ZONE ?)::time < ?::time', [$tz, $to]);
            }
        });
    }

    /** @param array<string, mixed> $c */
    private function filter(array $c): string
    {
        $v = fn (string $x) => QueryNormalizer::filterValue($x);
        $parts = [];
        if (isset($c['specialty'])) {
            $parts[] = 'specialty_ids:='.$v($c['specialty']);
        }
        if (isset($c['subspecialty'])) {
            $parts[] = 'subspecialty_ids:='.$v($c['subspecialty']);
        }
        if (isset($c['city'])) {
            $parts[] = 'city:='.$v($c['city']);
        }
        if (isset($c['province'])) {
            $parts[] = 'province:='.$v($c['province']);
        }
        if (isset($c['clinic_id'])) {
            $parts[] = 'clinic_id:='.$v($c['clinic_id']);
        }
        if (isset($c['consultation_type'])) {
            $parts[] = 'consultation_modes:='.$v($c['consultation_type']);
        }
        if (isset($c['gender'])) {
            $parts[] = 'gender:='.$v($c['gender']);
        }
        if (isset($c['language'])) {
            $parts[] = 'languages:='.$v($c['language']);
        }
        if (isset($c['fee_min'])) {
            $parts[] = 'fee_max:>='.(int) $c['fee_min'];
        }
        if (isset($c['fee_max'])) {
            $parts[] = 'fee_min:<='.(int) $c['fee_max'];
        }
        if (isset($c['experience_min'])) {
            $parts[] = 'years_experience:>='.(int) $c['experience_min'];
        }
        if (($c['verified'] ?? false) === true) {
            $parts[] = 'is_verified:=true';
        }
        if (($availability = self::availabilityFilter($c)) !== null) {
            $parts[] = $availability;
        }
        if (isset($c['lat'], $c['lng'])) {
            $parts[] = sprintf('location:(%F, %F, %F km)', (float) $c['lat'], (float) $c['lng'], (float) ($c['radius_km'] ?? 10));
        }

        return implode(' && ', $parts);
    }

    /**
     * The availability filter (4.8) in Philippine time, or null when none was asked
     * for. A date alone matches the day; times alone match any indexed day; both
     * match one window. Today never matches times that have already passed.
     *
     * @param  array<string, mixed>  $c
     */
    private static function availabilityFilter(array $c): ?string
    {
        $date = $c['available_date'] ?? null;
        $hasTime = isset($c['available_time_from']) || isset($c['available_time_to']);
        if ($date === null && ! $hasTime) {
            return null;
        }
        $minute = fn (string $hm) => (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2);
        $from = isset($c['available_time_from']) ? $minute((string) $c['available_time_from']) : 0;
        $to = isset($c['available_time_to']) ? $minute((string) $c['available_time_to']) : 1440;
        $now = now((string) config('mediconnect.timezone'));
        $today = $now->toDateString();
        if ($date !== null && $date !== $today && ! $hasTime) {
            return 'available_dates:='.QueryNormalizer::filterValue((string) $date);
        }

        // One key range per day: the asked day, or every indexed day for times alone. Today starts after the current minute.
        $days = $date !== null ? [(string) $date] : array_map(fn (int $i) => $now->addDays($i)->toDateString(), range(0, max(0, (int) config('search.availability_days', 30) - 1)));
        $ranges = [];
        foreach ($days as $day) {
            $lo = $day === $today ? max($from, $now->hour * 60 + $now->minute + 1) : $from;
            if ($lo <= $to - 1) {
                $key = DoctorDocuments::slotKey($day, 0);
                $ranges[] = ($key + $lo).'..'.($key + $to - 1);
            }
        }

        // No key is negative, so an empty window matches nothing.
        return $ranges === [] ? 'available_slot_keys:<0' : 'available_slot_keys:['.implode(', ', $ranges).']';
    }

    /** @param array<string, mixed> $c */
    private function sort(array $c, bool $browse): string
    {
        return match ($c['sort'] ?? 'relevance') {
            'fee' => 'fee_min(missing_values: last):asc,_text_match:desc',
            'distance' => isset($c['lat'], $c['lng']) ? sprintf('location(%F, %F):asc,_text_match:desc', (float) $c['lat'], (float) $c['lng']) : '_text_match:desc,rank_score:desc',
            'rating' => 'rating(missing_values: last):desc,rank_score:desc',
            'next_available' => 'next_available_at(missing_values: last):asc,rank_score:desc',
            default => $browse ? 'rank_score:desc' : '_text_match:desc,rank_score:desc',
        };
    }

    /** @param list<array<string, mixed>> $docs */
    private static function nextAvailable(array $docs): ?string
    {
        $now = now()->getTimestamp();
        $times = array_filter(array_map(fn (array $d) => isset($d['next_available_at']) ? (int) $d['next_available_at'] : null, $docs), fn (?int $t) => $t !== null && $t > $now);

        return $times === [] ? null : CarbonImmutable::createFromTimestamp(min($times))->toIso8601ZuluString();
    }

    /**
     * One result per doctor from their matching documents.
     *
     * @param  list<array<string, mixed>>  $docs
     * @return array<string, mixed>
     */
    private function summarise(array $docs): array
    {
        $first = $docs[0];
        $locations = array_values(array_filter($docs, fn (array $d) => isset($d['clinic_location_id'])));
        $fees = array_filter(array_map(fn (array $d) => $d['fee_min'] ?? null, $docs), fn ($f) => $f !== null);
        $from = $fees === [] ? null : min($fees);

        return [
            'doctor_id' => (string) $first['doctor_id'],
            'display_name' => (string) $first['display_name'],
            'professional_title' => $first['professional_title'] ?? null,
            'specialties' => array_values((array) ($first['specialties'] ?? [])),
            'languages' => array_values((array) ($first['languages'] ?? [])),
            'years_experience' => $first['years_experience'] ?? null,
            'is_verified' => (bool) ($first['is_verified'] ?? false),
            'offers_online' => array_filter($docs, fn (array $d) => in_array('ONLINE', (array) ($d['consultation_modes'] ?? []), true)) !== [],
            // Advisory (§11.6). An index time that has already passed is left out rather than shown.
            'next_available_at' => self::nextAvailable($docs),
            'fee_from' => $from === null ? null : ['amount' => (int) $from, 'currency' => 'PHP', 'formatted' => '₱'.number_format($from / 100, 2)],
            'locations' => array_map(fn (array $d) => [
                'clinic_id' => (string) $d['clinic_id'],
                'clinic_name' => (string) ($d['clinic_name'] ?? ''),
                'clinic_location_id' => (string) $d['clinic_location_id'],
                'location_name' => (string) ($d['location_name'] ?? ''),
                'city' => (string) ($d['city'] ?? ''),
                'fee_min' => $d['fee_min'] ?? null,
                'fee_max' => $d['fee_max'] ?? null,
                'next_available_at' => self::nextAvailable([$d]),
                'distance_km' => isset($d['geo_distance_meters']) ? round($d['geo_distance_meters'] / 1000, 1) : null,
            ], $locations),
        ];
    }
}
