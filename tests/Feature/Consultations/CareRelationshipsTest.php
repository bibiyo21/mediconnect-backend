<?php

use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Consultations\Contracts\CareRelationships;
use App\Modules\Consultations\Services\ConsultationsConfig;
use Carbon\CarbonImmutable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world();
    [, $this->profile] = remote_patient();
    $this->care = app(CareRelationships::class);
});

it('grants the queue doctor access until the end of the local day plus seven days, idempotently', function () {
    $entry = wi_seed_entry($this->w, $this->profile);
    // 23:30 in Manila on 2026-12-01 is 15:30 UTC the same day; end of the Manila day is 15:59:59.999999 UTC.
    $start = CarbonImmutable::parse('2026-12-01 23:30:00', 'Asia/Manila');

    $id = $this->care->grantForWalkIn($entry, $start);
    $again = $this->care->grantForWalkIn($entry, $start->addMinutes(5));

    $row = DB::table('care_relationships')->where('id', $id)->sole();
    $doctor = DB::table('walkin_queues as q')->join('walkin_queue_entries as e', 'e.walkin_queue_id', '=', 'q.id')->where('e.id', $entry)->value('q.doctor_profile_id');
    expect($again)->toBe($id)->and(DB::table('care_relationships')->count())->toBe(1)
        ->and($row->source_type)->toBe('WALK_IN')->and($row->status)->toBe('ACTIVE')->and($row->doctor_profile_id)->toBe($doctor)->and($row->patient_profile_id)->toBe($this->profile)
        ->and(CarbonImmutable::parse($row->starts_at)->equalTo($start))->toBeTrue()
        ->and(CarbonImmutable::parse($row->access_until)->format('Y-m-d H:i:s'))->toBe('2026-12-08 16:00:00');
});

it('uses the location timezone, not UTC, for the end of the day', function () {
    $entry = wi_seed_entry($this->w, $this->profile);
    DB::table('clinic_locations')->where('id', $this->w['location'])->update(['timezone' => 'America/New_York']);

    $id = $this->care->grantForWalkIn($entry, CarbonImmutable::parse('2026-12-01 03:00:00', 'UTC')); // 22:00 on 30 Nov in New York (UTC-5)

    expect(CarbonImmutable::parse(DB::table('care_relationships')->where('id', $id)->value('access_until'))->format('Y-m-d H:i:s'))->toBe('2026-12-08 05:00:00');
});

it('grants nothing for a guest', function () {
    $entry = wi_seed_entry($this->w, null, 'WAITING', null, 'STAFF_CONSOLE');

    expect($this->care->grantForWalkIn($entry, CarbonImmutable::now()))->toBeNull()->and(DB::table('care_relationships')->count())->toBe(0);
});

it('revokes an active relationship once and leaves it alone afterwards', function () {
    $entry = wi_seed_entry($this->w, $this->profile);
    $id = $this->care->grantForWalkIn($entry, $this->clock->now());

    $this->care->revokeForSource('WALK_IN', $entry);
    $first = DB::table('care_relationships')->where('id', $id)->sole();
    $this->clock->at = $this->clock->at->addSeconds(60);
    $this->care->revokeForSource('WALK_IN', $entry);
    $second = DB::table('care_relationships')->where('id', $id)->sole();

    expect($first->status)->toBe('REVOKED')->and($first->revoked_at)->not->toBeNull()->and($second->revoked_at)->toBe($first->revoked_at);
    $this->care->revokeForSource('WALK_IN', (string) Str::uuid7()); // unknown source: no error
});

it('refuses to boot with a bad care_access_days', function () {
    config(['consultations.care_access_days' => -1]);
    expect(fn () => ConsultationsConfig::fromConfig())->toThrow(InvalidArgumentException::class);
    config(['consultations.care_access_days' => 'abc']);
    expect(fn () => ConsultationsConfig::fromConfig())->toThrow(InvalidArgumentException::class);
    config(['consultations.care_access_days' => 0]);
    expect(fn () => ConsultationsConfig::fromConfig())->toThrow(InvalidArgumentException::class);
    config(['consultations.care_access_days' => 7]);
    expect(ConsultationsConfig::fromConfig()->careAccessDays)->toBe(7);
});

it('ends access at the exact next local midnight plus the days, across a DST change', function () {
    $entry = wi_seed_entry($this->w, $this->profile);
    DB::table('clinic_locations')->where('id', $this->w['location'])->update(['timezone' => 'America/New_York']);
    // The last second of 2026-03-01 in New York (UTC-5); DST starts on 8 March, so 8 days later is local midnight at UTC-4.
    $id = $this->care->grantForWalkIn($entry, CarbonImmutable::parse('2026-03-01 23:59:59', 'America/New_York'));

    expect(CarbonImmutable::parse(DB::table('care_relationships')->where('id', $id)->value('access_until'), 'UTC')->setTimezone('America/New_York')->format('Y-m-d H:i:s'))->toBe('2026-03-09 00:00:00');
});

it('refuses to grant again over a revoked, expired or other-doctor relationship', function () {
    foreach (['REVOKED', 'EXPIRED', 'DOCTOR'] as $case) {
        $entry = wi_seed_entry($this->w, $this->profile);
        $id = $this->care->grantForWalkIn($entry, $this->clock->now());
        if ($case === 'DOCTOR') {
            $other = directory_doctor('ACTIVE', 'Dr. Other '.Str::random(4), ClinicLocation::findOrFail($this->w['location']));
            // The entry is now assigned to another doctor than the one the relationship names.
            DB::table('walkin_queue_entries')->where('id', $entry)->update(['assigned_doctor_profile_id' => $other->id]);
        } else {
            DB::table('care_relationships')->where('id', $id)->update($case === 'REVOKED' ? ['status' => 'REVOKED', 'revoked_at' => now()] : ['status' => 'EXPIRED']);
        }

        expect(fn () => $this->care->grantForWalkIn($entry, $this->clock->now()))->toThrow(LogicException::class);
    }
});
