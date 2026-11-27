<?php

use App\Modules\WalkIns\Enums\EntryStatus as S;
use App\Modules\WalkIns\Services\EntryTransitions;

it('allows exactly the planned moves (D9, D10, D15)', function () {
    $legal = [
        'REGISTERED' => [S::Waiting, S::Cancelled],
        'WAITING' => [S::Called, S::Cancelled],
        'CALLED' => [S::InConsultation, S::Skipped, S::NoShow],
        'IN_CONSULTATION' => [S::Completed],
        'SKIPPED' => [S::Waiting, S::NoShow, S::Cancelled],
        'COMPLETED' => [],
        'NO_SHOW' => [],
        'CANCELLED' => [],
    ];
    foreach (S::cases() as $from) {
        foreach (S::cases() as $to) {
            expect(EntryTransitions::allows($from, $to))->toBe(in_array($to, $legal[$from->value], true), "{$from->value} to {$to->value}");
        }
        expect(EntryTransitions::targets($from))->toBe($legal[$from->value]);
    }
});

it('treats completed, no-show and cancelled as terminal', function () {
    foreach (S::cases() as $s) {
        expect(EntryTransitions::isTerminal($s))->toBe(in_array($s, [S::Completed, S::NoShow, S::Cancelled], true), $s->value);
    }
});

it('allows cancel from registered, waiting and skipped only', function () {
    foreach (S::cases() as $s) {
        expect(EntryTransitions::allows($s, S::Cancelled))->toBe(in_array($s, [S::Registered, S::Waiting, S::Skipped], true), $s->value);
    }
});

it('keeps held() equal to live plus skipped', function () {
    expect(S::held())->toBe([...S::live(), S::Skipped]);
});
