<?php

use App\Modules\Notifications\Realtime\ChannelAuthorizer;
use App\Modules\Users\Models\User;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;

/*
 * Increment 5.8-3 (review L1): the doctor's own channel needs an ACTIVE clinic assignment, not only the profile. When the last assignment
 * ends, the subscription is refused (and the realtime sweep closes live sockets on the same decision).
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->w = presence_world();
    $this->doctor = $this->w['doctor'];
    $this->user = User::query()->whereKey($this->doctor->user_id)->firstOrFail();
});

it('allows the doctor channel while the profile has an ACTIVE assignment, and refuses it once every assignment has ended', function () {
    $auth = app(ChannelAuthorizer::class);
    expect($auth->doctor($this->user, (string) $this->doctor->id))->toBeTrue();

    DB::table('doctor_clinic_assignments')->where('doctor_profile_id', $this->doctor->id)
        ->update(['status' => 'ENDED', 'effective_until' => now(), 'ended_by' => $this->user->id, 'end_reason' => 'test']);

    expect($auth->doctor($this->user, (string) $this->doctor->id))->toBeFalse();
});

it('refuses the doctor channel to another user, even with an ACTIVE assignment', function () {
    $stranger = User::factory()->create();

    expect(app(ChannelAuthorizer::class)->doctor($stranger, (string) $this->doctor->id))->toBeFalse();
});
