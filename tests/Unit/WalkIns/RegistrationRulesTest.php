<?php

use App\Modules\Clinics\DTOs\WalkInPolicy;
use App\Modules\Clinics\Enums\CapacityMode;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\PriorityLevel;
use App\Modules\WalkIns\Estimation\WaitBasis;
use App\Modules\WalkIns\Estimation\WaitEstimate;
use App\Modules\WalkIns\Services\EstimateView;
use App\Modules\WalkIns\Services\QueueLabel;
use App\Modules\WalkIns\Services\WalkInReader;
use Carbon\CarbonImmutable;

/*
 * Increment 5.5a: the pure rules of registration: queue labels (decision 93), the patient and staff estimate views (E3),
 * the walk-in policy defaults (A1), search term types, and the priority names the API uses (decision 88).
 */

function wi_estimate(?int $min, ?int $max, array $basis, ?bool $late = false, int $position = 2): WaitEstimate
{
    return new WaitEstimate('e', $position, $min, $max, $late, $basis, $min === null ? null : CarbonImmutable::now(), $max === null ? null : CarbonImmutable::now());
}

it('pads a queue number to at least two digits and never truncates', function () {
    expect(QueueLabel::of('A', 7))->toBe('A-07')->and(QueueLabel::of('A', 12))->toBe('A-12')->and(QueueLabel::of('A', 123))->toBe('A-123')->and(QueueLabel::of('DR', 1))->toBe('DR-01')
        ->and(QueueLabel::of(null, 5))->toBe('05')->and(QueueLabel::of('', 5))->toBe('05');
});

it('shows a patient the minutes only when the wait rests on something better than the default duration (E3)', function () {
    $service = EstimateView::forPatient(wi_estimate(10, 25, [WaitBasis::QueueAhead, WaitBasis::DurationService], true));
    $rolling = EstimateView::forPatient(wi_estimate(10, 25, [WaitBasis::DurationRolling]));
    $default = EstimateView::forPatient(wi_estimate(10, 25, [WaitBasis::QueueAhead, WaitBasis::DurationDefault]));
    $unknown = EstimateView::forPatient(wi_estimate(null, null, [WaitBasis::DoctorNotCheckedIn, WaitBasis::Unknown], null));

    expect($service)->toBe(['position' => 2, 'min_minutes' => 10, 'max_minutes' => 25, 'is_estimate' => true, 'may_not_be_served_today' => true, 'wait_status' => 'ESTIMATED'])
        ->and($rolling['wait_status'])->toBe('ESTIMATED')
        ->and($default)->toBe(['position' => 2, 'min_minutes' => null, 'max_minutes' => null, 'is_estimate' => true, 'may_not_be_served_today' => false, 'wait_status' => 'NOT_AVAILABLE_YET'])
        ->and($unknown)->toBe(['position' => 2, 'min_minutes' => null, 'max_minutes' => null, 'is_estimate' => true, 'may_not_be_served_today' => null, 'wait_status' => 'UNKNOWN'])
        ->and($service)->not->toHaveKey('basis');
});

it('gives staff the basis codes, and minutes only where asked', function () {
    $e = wi_estimate(10, 25, [WaitBasis::QueueAhead, WaitBasis::DurationService]);

    expect(EstimateView::forStaff($e, true))->toMatchArray(['min_minutes' => 10, 'max_minutes' => 25, 'wait_status' => 'ESTIMATED', 'basis' => ['queue_ahead', 'duration_service']])
        ->and(EstimateView::forStaff($e, false))->toMatchArray(['min_minutes' => null, 'max_minutes' => null, 'wait_status' => 'NOT_AVAILABLE_YET', 'position' => 2])
        ->and(EstimateView::forStaff(wi_estimate(null, null, [WaitBasis::Unknown]), true)['wait_status'])->toBe('UNKNOWN');
});

it('defaults the policy to remote registration off, a 30 minute cutoff and a derived capacity (A1, Q8)', function () {
    $d = WalkInPolicy::fromStored(null);

    expect($d->allowPatientAppRegistration)->toBeFalse()->and($d->cutoffMinutesBeforeClose)->toBe(30)->and($d->numberPrefix)->toBeNull()->and($d->capacityMode)->toBe(CapacityMode::Derived);
});

it('reads only well-formed stored values and ignores the rest, including the superseded pre-check-in key', function () {
    $p = WalkInPolicy::fromStored(['allow_patient_app_registration' => true, 'cutoff_minutes_before_close' => 0, 'number_prefix' => 'DR', 'capacity_mode' => 'UNLIMITED', 'allow_pre_checkin_registration' => true]);
    $bad = WalkInPolicy::fromStored(['allow_patient_app_registration' => 'true', 'cutoff_minutes_before_close' => '30', 'number_prefix' => 'dr', 'capacity_mode' => 'NONE']);

    expect($p->allowPatientAppRegistration)->toBeTrue()->and($p->cutoffMinutesBeforeClose)->toBe(0)->and($p->numberPrefix)->toBe('DR')->and($p->capacityMode)->toBe(CapacityMode::Unlimited)
        ->and($p->toStored())->toBe(['allow_patient_app_registration' => true, 'cutoff_minutes_before_close' => 0, 'number_prefix' => 'DR', 'capacity_mode' => 'UNLIMITED', 'recall_limit' => 2])
        ->and($bad->allowPatientAppRegistration)->toBeFalse()->and($bad->cutoffMinutesBeforeClose)->toBe(30)->and($bad->numberPrefix)->toBeNull()->and($bad->capacityMode)->toBe(CapacityMode::Derived);
    expect($p->with(['number_prefix' => null])->numberPrefix)->toBeNull()->and($p->with(['cutoff_minutes_before_close' => 15])->allowPatientAppRegistration)->toBeTrue();
});

it('tells a phone, a queue number and a name apart in a search term', function () {
    foreach (['0917 123 4567', '+639171234567', '9171234567', '0917-123-4567', '(0917) 1234567'] as $term) {
        expect(WalkInReader::termType($term))->toBe('phone');
    }
    foreach (['A-07', 'a07', '7', '07', 'DR-12', '123'] as $term) {
        expect(WalkInReader::termType($term))->toBe('number');
    }
    foreach (['maria', 'Juan Dela Cruz', 'o\'brien', 'A-B'] as $term) {
        expect(WalkInReader::termType($term))->toBe('name');
    }
    expect(WalkInReader::termIsBadPhone('0917 123 45'))->toBeTrue()->and(WalkInReader::termIsBadPhone('09171234567'))->toBeFalse()->and(WalkInReader::termIsBadPhone('maria'))->toBeFalse()->and(WalkInReader::termIsBadPhone('1234'))->toBeFalse();
});

it('exposes exactly three priority levels by name and never by number (decision 88)', function () {
    expect(array_map(fn (PriorityLevel $l) => [$l->apiName(), $l->value], PriorityLevel::cases()))->toBe([['NORMAL', 0], ['PRIORITY', 5], ['URGENT', 9]])
        ->and(PriorityLevel::fromApi('URGENT'))->toBe(PriorityLevel::Urgent)
        ->and(fn () => PriorityLevel::fromApi('9'))->toThrow(ValueError::class)->and(fn () => PriorityLevel::fromApi('urgent'))->toThrow(ValueError::class)
        ->and(PriorityLevel::Normal->needsReason())->toBeFalse()->and(PriorityLevel::Priority->needsReason())->toBeTrue();
});

it('keeps the live statuses in step with the database index', function () {
    expect(array_map(fn (EntryStatus $s) => $s->value, EntryStatus::live()))->toBe(['REGISTERED', 'WAITING', 'CALLED', 'IN_CONSULTATION']);
});

it('reads recall_limit between 1 and 10 and defaults anything else to 2', function () {
    expect(WalkInPolicy::fromStored(['recall_limit' => 5])->recallLimit)->toBe(5);
    foreach ([0, 11, '3', null, 2.5] as $bad) {
        expect(WalkInPolicy::fromStored(['recall_limit' => $bad])->recallLimit)->toBe(2);
    }
    expect((new WalkInPolicy)->with(['recall_limit' => 4])->toStored()['recall_limit'])->toBe(4);
});
