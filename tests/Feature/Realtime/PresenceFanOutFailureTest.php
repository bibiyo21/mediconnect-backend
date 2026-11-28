<?php

use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\WalkIns\Listeners\FanOutOnPresenceChange;
use App\Shared\Contracts\Clock;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * Increment 5.8-2 (review M3): the presence fan-out runs after the presence commit. A failure in it is logged with ids only and never becomes a
 * 500 for the doctor: the committed change still answers 200 and is kept.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    $this->w = walkin_world(['remote' => true]);
    doctor_in($this->w);
});

it('a fan-out that fails after the commit is logged, and the break still commits and answers 200', function () {
    $broken = Mockery::mock(Clock::class);
    $broken->shouldReceive('now')->andThrow(new RuntimeException('fan-out backend down'));
    app()->when(FanOutOnPresenceChange::class)->needs(Clock::class)->give(fn () => $broken);
    Log::spy();
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/presence/break/start', ['clinic_location_id' => $this->w['location'], 'expected_minutes' => 10], $this->w['doctorHeaders'])->assertOk();

    expect(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->where('current_status', 'ON_BREAK')->exists())->toBeTrue();
    Log::shouldHaveReceived('warning')->with('walkins.presence_fanout_failed', Mockery::on(function (array $context) {
        return ! isset($context['message']) && ($context['exception'] ?? null) === RuntimeException::class && isset($context['doctor_profile_id'], $context['clinic_location_id']);
    }))->once();
});
