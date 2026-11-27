<?php

use App\Modules\Consultations\Models\Consultation;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;

/*
 * Increment 5.7-1: derived BUSY (plan 5.7, D-H) and the break and unavailable refusal (D-I). BUSY is never stored: the stored status stays IN
 * during a consultation, the public status reads BUSY, and it reverts when the consultation ends.
 */

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
    fresh_session();
    $entry = register_walkin($this->patient, patient_body($this->w['service'], $this->profile))->assertCreated()->json('data.id');
    fresh_session();
    wi_post("{$entry}/arrive", $this->staff)->assertOk();
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    $this->entry = $entry;
    $this->consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
});

function public_status(string $doctor): string
{
    return test()->getJson("/api/v1/presence/doctors/{$doctor}")->assertOk()->json('data.status');
}

it('reads BUSY publicly while a consultation runs, and keeps the stored status IN', function () {
    $doctor = (string) $this->w['doctor']->id;
    expect(public_status($doctor))->toBe('IN');

    act_post("/api/v1/consultations/{$this->consultation}/start", $this->w['doctorHeaders'])->assertOk();

    expect(public_status($doctor))->toBe('BUSY');
    expect(DB::table('doctor_presence_sessions')->value('current_status'))->toBe('IN');
    expect(DB::table('doctor_presence_events')->where('new_status', 'BUSY')->count())->toBe(0);
});

it('reverts the public status to IN when the consultation ends', function () {
    $doctor = (string) $this->w['doctor']->id;
    act_post("/api/v1/consultations/{$this->consultation}/start", $this->w['doctorHeaders'])->assertOk();
    expect(public_status($doctor))->toBe('BUSY');

    act_post("/api/v1/consultations/{$this->consultation}/end", $this->w['doctorHeaders'])->assertOk();

    expect(public_status($doctor))->toBe('IN');
});

it('refuses a break and mark-unavailable while a consultation is in progress, without changing the stored status', function () {
    act_post("/api/v1/consultations/{$this->consultation}/start", $this->w['doctorHeaders'])->assertOk();

    fresh_session();

    presence_post('break/start', $this->w['doctorHeaders'], ['clinic_location_id' => $this->w['location'], 'expected_minutes' => 5])
        ->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION');
    fresh_session();
    presence_post('unavailable', $this->w['doctorHeaders'], ['clinic_location_id' => $this->w['location']])
        ->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION');

    expect(DB::table('doctor_presence_sessions')->value('current_status'))->toBe('IN');
});

it('allows a break once the consultation has ended', function () {
    act_post("/api/v1/consultations/{$this->consultation}/start", $this->w['doctorHeaders'])->assertOk();
    act_post("/api/v1/consultations/{$this->consultation}/end", $this->w['doctorHeaders'])->assertOk();

    fresh_session();

    presence_post('break/start', $this->w['doctorHeaders'], ['clinic_location_id' => $this->w['location'], 'expected_minutes' => 5])->assertOk();
});
