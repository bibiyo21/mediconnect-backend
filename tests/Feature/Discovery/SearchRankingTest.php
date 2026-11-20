<?php

use App\Modules\Discovery\Console\EvaluateSearchCommand;
use App\Modules\Discovery\Services\CircuitBreaker;
use App\Modules\Discovery\Services\DoctorSearch;
use App\Modules\Discovery\Services\RankScore;
use App\Modules\Discovery\Services\SearchIndexer;
use App\Shared\Contracts\SearchEngine;
use Database\Seeders\SearchRankingFixtureSeeder;
use Database\Seeders\SearchSynonymSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Str;

/*
 * Increment 3.5: ranking. rank_score and field weights are configuration;
 * the judged-query evaluation runs against live Typesense where reachable.
 */

it('scores profile completeness and damped rating, with no paid input', function () {
    expect(RankScore::of(100, null, 0))->toBeGreaterThan(RankScore::of(50, null, 0))
        // One 5-star review counts for less than many 4.6 reviews.
        ->and(RankScore::of(80, 5.0, 1))->toBeLessThan(RankScore::of(80, 4.6, 50))
        // No reviews: the prior, so a new doctor is neither boosted nor buried.
        ->and(RankScore::of(80, null, 0))->toBe(RankScore::of(80, 3.5, 0))
        ->and(RankScore::of(150, null, 0))->toBe(RankScore::of(100, null, 0));
    $weights = array_keys((array) config('search.ranking.rank_score'));
    expect(implode(',', $weights))->not->toContain('paid')->not->toContain('sponsor')->not->toContain('boost');
});

it('reads weights from configuration', function () {
    config(['search.ranking.rank_score.profile_completeness' => 1.0, 'search.ranking.rank_score.rating' => 0.0]);
    expect(RankScore::of(40, 5.0, 100))->toBe(0.4);
});

it('sends configured field weights and sorts browse queries by rank_score', function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    directory_doctor('ACTIVE', 'Dr. Ana Santos');
    $engine = app(SearchEngine::class);

    $this->getJson('/api/v1/search/doctors')->assertOk();
    $browse = end($engine->searches);
    $this->getJson('/api/v1/search/doctors?q=santos')->assertOk();
    $text = end($engine->searches);

    expect($browse['sort_by'])->toBe('rank_score:desc')
        ->and($text['sort_by'])->toBe('_text_match:desc,rank_score:desc')
        ->and($text['query_by'])->toStartWith('display_name,specialties')
        ->and($text['query_by_weights'])->toStartWith('8,5')
        ->and($text['prioritize_exact_match'])->toBeTrue();
});

it('meets the judged-query baseline on live Typesense', function () {
    $live = live_engine();
    if ($live === null) {
        $this->markTestSkipped('Typesense is not reachable here.');
    }
    config(['search.prefix' => 'mc_test_live_'.strtolower(Str::random(5))]);
    app()->instance(SearchEngine::class, $live);
    SearchIndexer::forgetInstalled();
    $prefix = (string) config('search.prefix');

    try {
        seed_roles();
        $this->seed(SpecialtySeeder::class);
        $this->seed(SearchSynonymSeeder::class);
        $this->seed(SearchRankingFixtureSeeder::class); // indexed through the outbox as it is written

        $summary = EvaluateSearchCommand::evaluate(app(DoctorSearch::class), app(CircuitBreaker::class));

        expect($summary['degraded'])->toBeFalse()
            ->and($summary['mean_p5'])->toBeGreaterThanOrEqual(0.9)
            ->and($summary['mrr'])->toBeGreaterThanOrEqual(0.9)
            ->and($summary['first_passed'])->toBe($summary['first_total']);
    } finally {
        foreach (['doctors', 'clinics', 'specialties'] as $name) {
            if (($target = $live->aliasTarget($prefix.'_'.$name)) !== null) {
                $live->dropCollection($target);
            }
        }
    }
});

it('refuses to seed the ranking fixture outside local and testing', function () {
    app()->detectEnvironment(fn () => 'production');
    expect(fn () => (new SearchRankingFixtureSeeder)->run())->toThrow(RuntimeException::class);
    app()->detectEnvironment(fn () => 'testing');
});
