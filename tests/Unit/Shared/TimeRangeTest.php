<?php

use App\Shared\Exceptions\InvalidValue;
use App\Shared\ValueObjects\TimeRange;
use Carbon\CarbonImmutable;

function range_utc(string $start, string $end): TimeRange
{
    return TimeRange::between(new CarbonImmutable($start, 'UTC'), new CarbonImmutable($end, 'UTC'));
}

it('requires the end to be after the start', function (string $start, string $end) {
    range_utc($start, $end);
})->with([
    'equal' => ['2026-11-17 01:00', '2026-11-17 01:00'],
    'reversed' => ['2026-11-17 02:00', '2026-11-17 01:00'],
])->throws(InvalidValue::class);

it('treats ranges as half-open, like tstzrange [)', function () {
    $nine = range_utc('2026-11-17 01:00', '2026-11-17 01:30');
    $nineThirty = range_utc('2026-11-17 01:30', '2026-11-17 02:00');
    $overlap = range_utc('2026-11-17 01:15', '2026-11-17 01:45');

    expect($nine->overlaps($nineThirty))->toBeFalse()
        ->and($nineThirty->overlaps($nine))->toBeFalse()
        ->and($nine->overlaps($overlap))->toBeTrue()
        ->and($overlap->overlaps($nineThirty))->toBeTrue();
});

it('contains its start but not its end', function () {
    $range = range_utc('2026-11-17 01:00', '2026-11-17 01:30');

    expect($range->contains(new CarbonImmutable('2026-11-17 01:00', 'UTC')))->toBeTrue()
        ->and($range->contains(new CarbonImmutable('2026-11-17 01:29:59', 'UTC')))->toBeTrue()
        ->and($range->contains(new CarbonImmutable('2026-11-17 01:30', 'UTC')))->toBeFalse();
});

it('normalizes to UTC so Manila times compare correctly', function () {
    $manila = TimeRange::between(
        new CarbonImmutable('2026-11-17 09:00', 'Asia/Manila'),
        new CarbonImmutable('2026-11-17 09:30', 'Asia/Manila'),
    );

    expect($manila->start->toIso8601ZuluString())->toBe('2026-11-17T01:00:00Z')
        ->and($manila->equals(range_utc('2026-11-17 01:00', '2026-11-17 01:30')))->toBeTrue()
        ->and($manila->durationInMinutes())->toBe(30);
});
