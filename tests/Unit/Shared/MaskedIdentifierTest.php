<?php

use App\Shared\ValueObjects\MaskedIdentifier;

it('keeps only the last four characters', function () {
    $masked = MaskedIdentifier::from('1234 5678 9012 3456');

    expect((string) $masked)->toBe('•••• 3456')
        ->and($masked->visibleSuffix)->toBe('3456')
        ->and(json_decode((string) json_encode($masked)))->toBe('•••• 3456');
});

it('reveals nothing for short values', function (string $value) {
    expect((string) MaskedIdentifier::from($value))->toBe('••••');
})->with(['12345', '1234', '', 'AB12CD34']);

it('supports a different number of visible characters', function () {
    expect((string) MaskedIdentifier::from('MEMBER-0099887766', 2))->toBe('•••• 66');
});
