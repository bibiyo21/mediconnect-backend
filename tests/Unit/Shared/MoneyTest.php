<?php

use App\Shared\Exceptions\InvalidValue;
use App\Shared\ValueObjects\Money;

it('defaults to PHP and normalizes the currency code', function () {
    expect(Money::of(150000)->currency)->toBe('PHP')
        ->and(Money::of(100, 'usd')->currency)->toBe('USD')
        ->and(Money::zero()->isZero())->toBeTrue();
});

it('rejects invalid currency codes', function (string $currency) {
    Money::of(100, $currency);
})->with(['PH', 'PESO', '12A', ''])->throws(InvalidValue::class);

it('adds and subtracts amounts of the same currency', function () {
    $a = Money::of(150000);
    $b = Money::of(2550);

    expect($a->add($b)->amount)->toBe(152550)
        ->and($a->subtract($b)->amount)->toBe(147450)
        ->and($b->subtract($a)->isNegative())->toBeTrue();
});

it('refuses arithmetic across currencies', function () {
    Money::of(100)->add(Money::of(100, 'USD'));
})->throws(InvalidValue::class, 'Currency mismatch');

it('computes percentages in basis points with half-up rounding', function (int $amount, int $bps, int $expected) {
    expect(Money::of($amount)->percentage($bps)->amount)->toBe($expected);
})->with([
    'exact 15% of 1,500.00' => [150000, 1500, 22500],
    '12.5% of 19.99 rounds up from .875' => [1999, 1250, 250],
    'rounds down below half' => [1001, 1000, 100],
    'exactly half rounds up' => [5, 1000, 1],
    'negative rounds away from zero' => [-1999, 1250, -250],
    'zero basis points' => [150000, 0, 0],
    '100%' => [150000, 10000, 150000],
]);

it('rejects negative basis points', function () {
    Money::of(100)->percentage(-1);
})->throws(InvalidValue::class);

it('formats for display', function (int $amount, string $currency, string $expected) {
    expect(Money::of($amount, $currency)->format())->toBe($expected);
})->with([
    [150000, 'PHP', '₱1,500.00'],
    [5, 'PHP', '₱0.05'],
    [123456789, 'PHP', '₱1,234,567.89'],
    [-2550, 'PHP', '-₱25.50'],
    [1250, 'USD', 'USD 12.50'],
]);

it('serializes to the API money shape', function () {
    expect(json_decode((string) json_encode(Money::of(150000)), true))
        ->toBe(['amount' => 150000, 'currency' => 'PHP', 'formatted' => '₱1,500.00']);
});

it('compares by amount and currency', function () {
    expect(Money::of(100)->equals(Money::of(100)))->toBeTrue()
        ->and(Money::of(100)->equals(Money::of(100, 'USD')))->toBeFalse()
        ->and(Money::of(200)->greaterThan(Money::of(100)))->toBeTrue();
});
