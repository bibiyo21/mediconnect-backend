<?php

use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Presence\Enums\PublicPresenceStatus;
use App\Modules\Presence\Services\PresenceTransitions;

/*
 * Increment 5.3, exit test "presence transitions" (architecture.md §9.2): the whole table, every
 * pair of statuses, so a new legal move shows up as a deliberate edit here.
 */

it('allows exactly the documented moves and no others', function () {
    $legal = [
        'NOT_CHECKED_IN' => ['IN'],
        'IN' => ['ON_BREAK', 'TEMPORARILY_UNAVAILABLE', 'OUT'],
        'ON_BREAK' => ['IN', 'OUT'],
        'TEMPORARILY_UNAVAILABLE' => ['IN', 'OUT'],
        'OUT' => [],
        'BUSY' => [],
    ];

    foreach (PresenceStatus::cases() as $from) {
        $allowed = collect(PresenceStatus::cases())->filter(fn (PresenceStatus $to) => PresenceTransitions::allows($from, $to))->map->value->values()->all();
        expect($allowed)->toEqualCanonicalizing($legal[$from->value], "from {$from->value}");
    }
});

it('never lets BUSY be a source or a target (it is derived, not stored)', function () {
    foreach (PresenceStatus::cases() as $other) {
        expect(PresenceTransitions::allows(PresenceStatus::Busy, $other))->toBeFalse()
            ->and(PresenceTransitions::allows($other, PresenceStatus::Busy))->toBeFalse();
    }
});

it('maps stored statuses to the whitelisted public values (Q13)', function () {
    expect(PublicPresenceStatus::of(PresenceStatus::In))->toBe(PublicPresenceStatus::In)
        ->and(PublicPresenceStatus::of(PresenceStatus::OnBreak))->toBe(PublicPresenceStatus::OnBreak)
        ->and(PublicPresenceStatus::of(PresenceStatus::TemporarilyUnavailable))->toBe(PublicPresenceStatus::Away)
        ->and(PublicPresenceStatus::of(PresenceStatus::Busy))->toBe(PublicPresenceStatus::Busy)
        ->and(PublicPresenceStatus::of(PresenceStatus::Out))->toBe(PublicPresenceStatus::NotIn)
        ->and(PublicPresenceStatus::of(PresenceStatus::NotCheckedIn))->toBe(PublicPresenceStatus::NotIn)
        ->and(array_map(fn (PublicPresenceStatus $s) => $s->value, PublicPresenceStatus::cases()))->toBe(['IN', 'BUSY', 'ON_BREAK', 'AWAY', 'NOT_IN']);
});
