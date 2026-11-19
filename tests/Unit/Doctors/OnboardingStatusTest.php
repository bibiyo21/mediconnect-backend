<?php

use App\Modules\Doctors\Enums\OnboardingStatus as S;

it('allows only the documented onboarding transitions (CLAUDE.md §5.2)', function () {
    $allowed = [];
    foreach (S::cases() as $from) {
        foreach (S::cases() as $to) {
            if ($from->canMoveTo($to)) {
                $allowed[] = "{$from->value}->{$to->value}";
            }
        }
    }

    expect($allowed)->toBe([
        'REGISTERED->DOCUMENTS_SUBMITTED',
        'DOCUMENTS_SUBMITTED->UNDER_REVIEW',
        'UNDER_REVIEW->REJECTED',
        'UNDER_REVIEW->VERIFIED',
        'REJECTED->DOCUMENTS_SUBMITTED',
        'VERIFIED->ACTIVE',
    ]);
});

it('locks the licence number from review onwards', function () {
    expect(array_map(fn (S $s) => $s->value, array_values(array_filter(S::cases(), fn (S $s) => $s->licenceLocked()))))
        ->toBe(['UNDER_REVIEW', 'VERIFIED', 'ACTIVE']);
});
