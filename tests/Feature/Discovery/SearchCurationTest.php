<?php

use App\Modules\Discovery\Models\SearchSynonym;
use App\Modules\Discovery\Models\SearchZeroResult;
use App\Modules\Rbac\Enums\Role;
use App\Shared\Contracts\SearchEngine;
use Database\Seeders\SearchSynonymSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Increment 3.4: search analytics (no identity), the zero-result queue and
 * synonyms managed under `search.manage` (D-42).
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    $this->engine = app(SearchEngine::class);
});

function curator(Role $role = Role::MedicalAdmin): array
{
    app('auth')->forgetGuards();
    $user = account($role);
    $headers = bearer(enroll_mfa($user)[2]);
    app('auth')->forgetGuards();

    return [$user, $headers];
}

it('records each search without identity or coordinates', function () {
    directory_doctor('ACTIVE', 'Dr. Ana Santos');
    $patient = signed_in(patient_account());

    $this->getJson('/api/v1/search/doctors?q=Santos&consultation_type=ONLINE&lat=14.55&lng=121.02&radius_km=5', $patient)->assertOk();

    $row = DB::table('search_analytics')->latest('id')->first();
    expect($row->collection)->toBe('doctors')
        ->and($row->normalized_query)->toBe('santos')
        ->and($row->result_count)->toBe(1)
        ->and(json_decode((string) $row->filters, true))->toEqualCanonicalizing(['consultation_type' => 'ONLINE', 'geo' => true])
        ->and((array) $row)->not->toHaveKeys(['user_id', 'ip', 'ip_hash', 'lat', 'lng'])
        ->and(json_encode($row))->not->toContain('14.55')->not->toContain('121.02');
});

it('queues zero-result queries and counts repeats, but not degraded answers', function () {
    $this->getJson('/api/v1/search/doctors?q=Kardyolohista')->assertOk();
    $this->getJson('/api/v1/search/doctors?q=kardyolohista%20')->assertOk();

    $zero = SearchZeroResult::where('normalized_query', 'kardyolohista')->firstOrFail();
    expect($zero->occurrences)->toBe(2)->and($zero->status)->toBe('NEW');

    $this->engine->down = true;
    $this->getJson('/api/v1/search/doctors?q=nothing-here')->assertJsonPath('meta.degraded', true);
    expect(SearchZeroResult::where('normalized_query', 'nothing-here')->exists())->toBeFalse();
});

it('lets a medical admin add a synonym from the zero-result queue, pushing it to the engine', function () {
    $this->getJson('/api/v1/search/doctors?q=kardyolohista')->assertOk();
    $zero = SearchZeroResult::firstOrFail();
    [, $headers] = curator();

    $queue = $this->getJson('/api/v1/admin/search/zero-results', $headers)->assertOk()->json('data');
    expect($queue[0]['query'])->toBe('kardyolohista');

    $created = $this->postJson('/api/v1/admin/search/synonyms', ['synonyms' => ['Kardyolohista', 'cardiologist', 'cardiology'], 'zero_result_id' => $zero->id], $headers)->assertCreated()->json('data');

    expect($created['synonyms'])->toBe(['kardyolohista', 'cardiologist', 'cardiology'])
        ->and($created['synced'])->toBeTrue()
        ->and($zero->refresh()->status)->toBe('SYNONYM_ADDED')
        ->and(collect($this->engine->synonymSets['medical'])->pluck('synonyms')->flatten()->all())->toContain('kardyolohista')
        ->and(DB::table('audit_logs')->where('action', 'search.synonym_created')->exists())->toBeTrue();
});

it('deactivates a synonym and removes it from the engine set', function () {
    $this->seed(SearchSynonymSeeder::class);
    [, $headers] = curator(Role::Admin);
    $synonym = SearchSynonym::whereRaw("synonyms @> ARRAY['pedia']")->firstOrFail();

    $this->patchJson("/api/v1/admin/search/synonyms/{$synonym->id}", ['status' => 'INACTIVE'], $headers)->assertOk()->assertJsonPath('data.status', 'INACTIVE');

    expect(collect($this->engine->synonymSets['medical'])->pluck('id')->all())->not->toContain($synonym->id);
    $this->getJson('/api/v1/admin/search/synonyms', $headers)->assertOk();
});

it('ignores a zero-result query', function () {
    $this->getJson('/api/v1/search/doctors?q=asdfgh')->assertOk();
    [, $headers] = curator();

    $this->postJson('/api/v1/admin/search/zero-results/'.SearchZeroResult::firstOrFail()->id.'/ignore', [], $headers)->assertOk()->assertJsonPath('data.status', 'IGNORED');
    $this->getJson('/api/v1/admin/search/zero-results', $headers)->assertOk()->assertJsonCount(0, 'data');
});

it('validates synonyms', function () {
    [, $headers] = curator();
    $this->postJson('/api/v1/admin/search/synonyms', ['synonyms' => ['only-one']], $headers)->assertStatus(422)->assertJsonValidationErrors('synonyms');
    $this->postJson('/api/v1/admin/search/synonyms', ['synonyms' => ['a', 'A']], $headers)->assertStatus(422);
});

it('reports volume, rates and latency percentiles', function () {
    foreach ([[10, 3, false], [20, 0, true], [300, 5, false], [40, 1, false]] as [$ms, $count, $hit]) {
        DB::table('search_analytics')->insert(['collection' => 'doctors', 'query_hash' => str_repeat('a', 64), 'normalized_query' => 'santos', 'filters' => '{}', 'result_count' => $count, 'latency_ms' => $ms, 'cache_hit' => $hit, 'degraded' => false, 'occurred_at' => now()]);
    }
    [, $headers] = curator();

    $o = $this->getJson('/api/v1/admin/search/overview', $headers)->assertOk()->json('data');

    expect($o['searches'])->toBe(4)
        ->and($o['zero_result_rate'])->toBe(0.25)
        ->and($o['cache_hit_rate'])->toBe(0.25)
        ->and($o['latency_ms']['p50'])->toBe(30)
        ->and($o['top_queries'][0])->toBe(['query' => 'santos', 'searches' => 4]);
});

it('limits curation to search.manage holders with MFA', function () {
    [, $clinic] = curator(Role::ClinicManager);
    $this->getJson('/api/v1/admin/search/overview', $clinic)->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/admin/search/synonyms', signed_in(patient_account()))->assertForbidden();
    app('auth')->forgetGuards();
    $limited = native_login(account(Role::MedicalAdmin))->json('data.tokens.access_token');
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/admin/search/overview', bearer($limited))->assertStatus(401)->assertJsonPath('code', 'MFA_REQUIRED');
});

it('seeds synonyms once, from the specialty catalogue and Filipino phrases', function () {
    $this->seed(SearchSynonymSeeder::class);
    $count = SearchSynonym::count();
    $this->seed(SearchSynonymSeeder::class);

    expect(SearchSynonym::count())->toBe($count)
        ->and(SearchSynonym::whereRaw("synonyms @> ARRAY['pedia', 'pediatrics']")->exists())->toBeTrue()
        ->and(SearchSynonym::whereRaw("synonyms @> ARRAY['doktor ng bata']")->exists())->toBeTrue();
});

it('creates upcoming analytics partitions and drops expired ones', function () {
    $owner = DB::connection('pgsql_owner');
    $owner->statement("CREATE TABLE IF NOT EXISTS search_analytics_p2020_01 PARTITION OF search_analytics FOR VALUES FROM ('2020-01-01') TO ('2020-02-01')");

    Artisan::call('search:partitions');

    expect($owner->scalar("select to_regclass('search_analytics_p2020_01')"))->toBeNull()
        ->and($owner->scalar("select to_regclass('search_analytics_p".now()->addMonth()->format('Y_m')."')"))->not->toBeNull();
});
