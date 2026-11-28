<?php

use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 5.8-3 (S1): the public cancel endpoint accepts only the four caller reasons. The system-only codes (END_OF_DAY and
 * DOCTOR_CHECKED_OUT) are written by the sweep and the check-out, never by a patient or staff member, so the audit trail stays true.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    [$this->user, $this->profile, $this->headers] = remote_patient();
    doctor_in($this->w);
    $this->entry = register_walkin($this->headers, patient_body($this->w['service'], $this->profile))->assertCreated()->json('data.id');
});

it('answers 422 for each system-only reason, and leaves the entry live', function (string $reason) {
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/walk-ins/{$this->entry}/cancel", ['reason_code' => $reason], [...$this->headers, 'Idempotency-Key' => (string) Str::uuid7()])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason_code']);

    expect(DB::table('walkin_queue_entries')->where('id', $this->entry)->value('status'))->toBe('REGISTERED')
        ->and(DB::table('walkin_queue_events')->where('event_type', 'CANCELLED')->count())->toBe(0);
})->with(['END_OF_DAY', 'DOCTOR_CHECKED_OUT']);

it('still accepts a caller reason', function () {
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/walk-ins/{$this->entry}/cancel", ['reason_code' => 'PATIENT_REQUEST'], [...$this->headers, 'Idempotency-Key' => (string) Str::uuid7()])
        ->assertOk();
});
