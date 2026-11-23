<?php

use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Estimation\DurationSource;
use App\Modules\WalkIns\Estimation\EstimateInputs;
use App\Modules\WalkIns\Estimation\EstimationConfig;
use App\Modules\WalkIns\Estimation\EstimatorSettings;
use App\Modules\WalkIns\Estimation\PresenceInput;
use App\Modules\WalkIns\Estimation\QueueEntryInput;
use App\Modules\WalkIns\Estimation\WaitTimeEstimator;
use App\Modules\WalkIns\Providers\WalkInsServiceProvider;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;

/*
 * Increment 5.4: the estimator is configured from config/walkins.php through the container, and a bad
 * setting stops the application at boot instead of producing odd waiting times.
 */

it('hands the configured settings to an estimator resolved from the container', function () {
    $now = CarbonImmutable::parse('2026-12-07 10:00', 'Asia/Manila')->utc();
    app()->instance(Clock::class, new PinnedClock($now));
    $inputs = new EstimateInputs(
        [new QueueEntryInput('a', 1, 0, EntryStatus::Waiting, 600, DurationSource::Service, $now->subMinutes(30)), new QueueEntryInput('b', 2, 0, EntryStatus::Waiting, 600, DurationSource::Service, $now->subMinutes(20))],
        null, [], new PresenceInput(PresenceStatus::In), null, 'Asia/Manila', '2026-12-07',
    );

    $defaults = app(WaitTimeEstimator::class)->estimate($inputs);
    expect([$defaults['b']->minMinutes, $defaults['b']->maxMinutes])->toBe([7, 13]);

    config(['walkins.estimation.range_spread_percent' => 50]);
    $wider = app(WaitTimeEstimator::class)->estimate($inputs);
    expect([$wider['b']->minMinutes, $wider['b']->maxMinutes])->toBe([5, 15]);

    config(['walkins.estimation.range_spread_percent' => 25, 'walkins.estimation.min_range_width_minutes' => 20, 'walkins.estimation.max_lateness_minutes' => 60]);
    $settings = app(EstimatorSettings::class);
    $widest = app(WaitTimeEstimator::class)->estimate($inputs);
    expect([$settings->spreadPercent, $settings->minRangeWidthMinutes, $settings->maxLatenessMinutes])->toBe([25, 20, 60])
        ->and([$widest['a']->minMinutes, $widest['a']->maxMinutes])->toBe([0, 20]);
});

it('ships a valid default configuration', function () {
    $c = EstimationConfig::fromConfig();

    expect([$c->windowSize, $c->minSamples, $c->maxAgeDays, $c->defaultMinutes, $c->spreadPercent, $c->minRangeWidthMinutes, $c->maxLatenessMinutes])->toBe([20, 3, 180, 15, 25, 5, 30])
        ->and($c->trimFraction)->toBe(0.1);
});

it('fails loudly on a bad setting', function (string $key, mixed $value) {
    config(["walkins.estimation.{$key}" => $value]);

    expect(fn () => EstimationConfig::fromConfig())->toThrow(InvalidArgumentException::class, 'Invalid walkins.estimation configuration')
        ->and(fn () => EstimatorSettings::fromConfig())->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new WalkInsServiceProvider(app()))->boot())->toThrow(InvalidArgumentException::class);
})->with([
    'window size zero' => ['window_size', 0],
    'trim half or more' => ['trim_fraction', 0.5],
    'negative trim' => ['trim_fraction', -0.1],
    'min samples above window' => ['min_samples', 21],
    'min samples zero' => ['min_samples', 0],
    'max age zero' => ['max_age_days', 0],
    'default minutes zero' => ['default_minutes', 0],
    'spread zero' => ['range_spread_percent', 0],
    'spread too wide' => ['range_spread_percent', 95],
    'width zero' => ['min_range_width_minutes', 0],
    'lateness below width' => ['max_lateness_minutes', 4],
]);

it('fails loudly on a missing setting', function () {
    config(['walkins.estimation' => ['window_size' => 20]]);

    expect(fn () => EstimationConfig::fromConfig())->toThrow(InvalidArgumentException::class, 'Missing walkins.estimation');
});
