<?php

use App\Modules\Consultations\Contracts\CareRelationships;
use App\Modules\Consultations\DTOs\WalkInCareGrant;
use App\Modules\Consultations\Models\Consultation;
use Carbon\CarbonImmutable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/* Increment 5.7-1: the care relationship is granted by a start (B10), with the exact access window, and kept by an end. */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    consultation_clock($this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    [, $this->profile, $this->patient] = remote_patient();
});

it('grants at start with the access window ending at the local day plus the configured days (Asia/Manila)', function () {
    fresh_session();
    $entry = register_walkin($this->patient, patient_body($this->w['service'], $this->profile))->assertCreated()->json('data.id');
    fresh_session();
    wi_post("{$entry}/arrive", $this->staff)->assertOk();
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    $startedAt = $this->clock->now();

    act_post("/api/v1/consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();

    $row = DB::table('care_relationships')->where('source_id', $entry)->sole();
    $expected = CarbonImmutable::instance($startedAt)->setTimezone('Asia/Manila')->startOfDay()->addDays(8)->utc();
    expect($row->status)->toBe('ACTIVE')->and($row->source_type)->toBe('WALK_IN')
        ->and(CarbonImmutable::parse($row->access_until)->equalTo($expected))->toBeTrue()
        ->and($row->patient_profile_id)->toBe($this->profile);
});

it('keeps the grant when the consultation ends', function () {
    fresh_session();
    $entry = register_walkin($this->patient, patient_body($this->w['service'], $this->profile))->assertCreated()->json('data.id');
    fresh_session();
    wi_post("{$entry}/arrive", $this->staff)->assertOk();
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    act_post("/api/v1/consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    wi_advance(3);

    act_post("/api/v1/consultations/{$consultation}/end", $this->w['doctorHeaders'])->assertOk();

    expect(DB::table('care_relationships')->where('source_id', $entry)->value('status'))->toBe('ACTIVE');
});

it('refuses a grant of a doctor\'s own patient profile with a LogicException and no row (D-16, defensive)', function () {
    $doctorUser = DB::table('doctor_profiles')->where('id', $this->w['doctor']->id)->value('user_id');
    $own = (string) DB::table('patient_profiles')->where('user_id', $doctorUser)->value('id');
    expect($own)->not->toBe('');

    expect(fn () => app(CareRelationships::class)->grantForWalkIn(new WalkInCareGrant((string) Str::uuid7(), $this->w['doctor']->id, $own, 'Asia/Manila', $this->clock->now())))
        ->toThrow(LogicException::class);
    expect(DB::table('care_relationships')->count())->toBe(0);
});
