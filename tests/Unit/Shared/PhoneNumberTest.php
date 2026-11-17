<?php

use App\Shared\Exceptions\InvalidValue;
use App\Shared\ValueObjects\PhoneNumber;

it('normalizes common Philippine mobile spellings to E.164', function (string $input) {
    expect(PhoneNumber::parse($input)->e164)->toBe('+639171234567');
})->with([
    '09171234567',
    '9171234567',
    '639171234567',
    '+639171234567',
    '+63 917 123 4567',
    '0917-123-4567',
    '(0917) 123.4567',
    '  09171234567  ',
]);

it('rejects numbers that are not Philippine mobiles', function (string $input) {
    PhoneNumber::parse($input);
})->with([
    'landline' => ['0281234567'],
    'too short' => ['0917123456'],
    'too long' => ['091712345678'],
    'other country' => ['+14155552671'],
    'letters' => ['0917abc4567'],
    'empty' => [''],
])->throws(InvalidValue::class);

it('masks for display', function () {
    expect(PhoneNumber::parse('09171234567')->masked())->toBe('+63 9•• ••• 4567');
});

it('compares and serializes by E.164', function () {
    $a = PhoneNumber::parse('09171234567');

    expect($a->equals(PhoneNumber::parse('+639171234567')))->toBeTrue()
        ->and((string) $a)->toBe('+639171234567')
        ->and(json_encode($a))->toBe('"+639171234567"');
});
