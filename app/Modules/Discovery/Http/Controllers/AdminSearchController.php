<?php

namespace App\Modules\Discovery\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Discovery\Http\Requests\CreateSynonymRequest;
use App\Modules\Discovery\Http\Requests\UpdateSynonymRequest;
use App\Modules\Discovery\Models\SearchSynonym;
use App\Modules\Discovery\Models\SearchZeroResult;
use App\Modules\Discovery\Services\SynonymSync;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Search curation for `search.manage` (D-42, increment 3.4): metrics, the
 * zero-result queue and synonyms. Analytics carry no user identity.
 */
final class AdminSearchController
{
    public function __construct(private readonly SynonymSync $sync, private readonly RecordsAudit $audit) {}

    /** Search health over the last N days (default 7): volume, rates, latency percentiles, top queries. */
    public function overview(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->query('days', '7')));
        $since = now()->subDays($days);
        $base = DB::table('search_analytics')->where('occurred_at', '>=', $since)->whereIn('collection', ['doctors', 'clinics']);
        $row = (clone $base)->selectRaw('count(*) as total, coalesce(avg((result_count = 0)::int), 0) as zero_rate, coalesce(avg(cache_hit::int), 0) as cache_rate, coalesce(avg(degraded::int), 0) as degraded_rate,
            percentile_cont(0.5) within group (order by latency_ms) as p50, percentile_cont(0.95) within group (order by latency_ms) as p95, percentile_cont(0.99) within group (order by latency_ms) as p99')->first();
        $top = (clone $base)->where('normalized_query', '<>', '')->selectRaw('normalized_query, count(*) as searches')->groupBy('normalized_query')->orderByDesc('searches')->limit(10)->get();

        return ApiResponse::success([
            'days' => $days,
            'searches' => (int) ($row->total ?? 0),
            /** Share of searches with no results (0-1). */
            'zero_result_rate' => round((float) ($row->zero_rate ?? 0), 4),
            'cache_hit_rate' => round((float) ($row->cache_rate ?? 0), 4),
            /** Share answered by the PostgreSQL fallback. */
            'degraded_rate' => round((float) ($row->degraded_rate ?? 0), 4),
            /** Milliseconds, as measured by the API. */
            'latency_ms' => ['p50' => self::ms($row->p50 ?? null), 'p95' => self::ms($row->p95 ?? null), 'p99' => self::ms($row->p99 ?? null)],
            'top_queries' => $top->map(fn (object $t) => ['query' => (string) $t->normalized_query, 'searches' => (int) $t->searches])->values()->all(),
            'open_zero_results' => SearchZeroResult::where('status', 'NEW')->count(),
        ]);
    }

    public function zeroResults(Request $request): JsonResponse
    {
        $status = $request->query('status', 'NEW');
        if (! in_array($status, ['NEW', 'SYNONYM_ADDED', 'IGNORED'], true)) {
            throw ValidationException::withMessages(['status' => ['Unknown status.']]);
        }
        $page = SearchZeroResult::where('status', $status)->orderByDesc('occurrences')->orderByDesc('last_seen_at')->paginate(25);

        return ApiResponse::success(array_map(fn (SearchZeroResult $z) => self::zero($z), $page->items()), meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function ignore(Request $request, string $zeroResultId): JsonResponse
    {
        $zero = SearchZeroResult::findOrFail($zeroResultId);
        $zero->forceFill(['status' => 'IGNORED', 'reviewed_by' => self::user($request)->id, 'reviewed_at' => now()])->save();
        $this->audit->record('search.zero_result_ignored', 'search_zero_result', $zero->id);

        return ApiResponse::success(self::zero($zero));
    }

    public function synonyms(Request $request): JsonResponse
    {
        $rows = SearchSynonym::query()->when($request->query('status') !== 'all', fn ($q) => $q->where('status', 'ACTIVE'))->orderBy('created_at')->get();

        return ApiResponse::success($rows->map(fn (SearchSynonym $s) => self::synonym($s))->values()->all());
    }

    public function store(CreateSynonymRequest $request): JsonResponse
    {
        $synonym = DB::transaction(function () use ($request) {
            $s = new SearchSynonym;
            $s->forceFill([
                'synonyms' => self::terms((array) $request->validated('synonyms')),
                'root_term' => self::root($request->validated('root_term')),
                'created_by' => self::user($request)->id, 'updated_by' => self::user($request)->id,
            ])->save();
            if (($zeroId = $request->validated('zero_result_id')) !== null) {
                SearchZeroResult::whereKey($zeroId)->update(['status' => 'SYNONYM_ADDED', 'reviewed_by' => self::user($request)->id, 'reviewed_at' => now()]);
            }
            $this->audit->record('search.synonym_created', 'search_synonym', $s->id, ['terms' => count($s->synonyms)]);

            return $s;
        });
        $synced = $this->sync->push();

        return ApiResponse::success([...self::synonym($synonym), 'synced' => $synced], status: 201);
    }

    public function update(UpdateSynonymRequest $request, string $synonymId): JsonResponse
    {
        $s = SearchSynonym::findOrFail($synonymId);
        $data = $request->validated();
        $s->forceFill(array_filter([
            'synonyms' => isset($data['synonyms']) ? self::terms((array) $data['synonyms']) : null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null));
        if (array_key_exists('root_term', $data)) {
            $s->root_term = self::root($data['root_term']);
        }
        $s->updated_by = self::user($request)->id;
        $s->save();
        $this->audit->record('search.synonym_updated', 'search_synonym', $s->id, ['status' => $s->status]);
        $synced = $this->sync->push();

        return ApiResponse::success([...self::synonym($s), 'synced' => $synced]);
    }

    /**
     * @param  array<mixed>  $terms
     * @return list<string>
     */
    private static function terms(array $terms): array
    {
        return array_values(array_unique(array_map(fn ($t) => mb_strtolower(trim((string) $t)), $terms)));
    }

    private static function root(mixed $root): ?string
    {
        $root = is_string($root) ? mb_strtolower(trim($root)) : '';

        return $root === '' ? null : $root;
    }

    /** @return array{id: string, query: string, collection: string, occurrences: int, first_seen_at: string, last_seen_at: string, status: string} */
    private static function zero(SearchZeroResult $z): array
    {
        return ['id' => $z->id, 'query' => $z->normalized_query, 'collection' => $z->collection, 'occurrences' => $z->occurrences, 'first_seen_at' => $z->first_seen_at->toIso8601ZuluString(), 'last_seen_at' => $z->last_seen_at->toIso8601ZuluString(), 'status' => $z->status];
    }

    /** @return array{id: string, synonyms: list<string>, root_term: string|null, status: string, updated_at: string} */
    private static function synonym(SearchSynonym $s): array
    {
        return ['id' => $s->id, 'synonyms' => $s->synonyms, 'root_term' => $s->root_term, 'status' => $s->status, 'updated_at' => $s->updated_at->toIso8601ZuluString()];
    }

    private static function ms(mixed $value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }

    private static function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
