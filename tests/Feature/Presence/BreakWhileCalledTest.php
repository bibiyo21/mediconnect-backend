<?php

use App\Modules\Consultations\Models\Consultation;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;

/*
 * Increment 5.8-3 (HAG-43, owner 2026-11-28): a break waits while a walk-in patient is CALLED to the doctor, anywhere, as check-out does.
 * An INTERRUPTED consultation does not block a break.
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
    $this->doctorId = (string) $this->w['doctor']->id;
});

it('refuses a break while a walk-in patient is called to the doctor (409 ACTIVE_CONSULTATION, called), and the session stays IN', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk()->assertJsonPath('data.id', $entry);

    presence_post('break/start', $this->w['doctorHeaders'], ['expected_minutes' => 15])
        ->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION')->assertJsonPath('reason_code', 'called');

    expect(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN')
        ->and(wi_status($entry))->toBe('CALLED');
});

it('allows a break while a consultation is interrupted, and refuses one only while the patient is still called', function () {
    $entry = walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    act_post("/api/v1/consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    act_post("/api/v1/consultations/{$consultation}/interrupt", $this->w['doctorHeaders'])->assertOk();

    presence_post('break/start', $this->w['doctorHeaders'], ['expected_minutes' => 15])->assertOk()->assertJsonPath('data.status', 'ON_BREAK');
    expect(Consultation::query()->whereKey($consultation)->value('status')->value)->toBe('INTERRUPTED');
});
