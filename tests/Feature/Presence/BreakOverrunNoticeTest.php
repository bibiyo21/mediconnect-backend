<?php

use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Presence\Jobs\SendBreakReminder;
use App\Modules\WalkIns\Contracts\BreakOverrunNotices;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;

/*
 * Increment 5.8-3 (D-P, owner OQ-C): when a break runs past its expected end, the walk-in patients still WAITING or REGISTERED in the doctor's
 * queues get one in-app notice (generic text, no push). It rides the existing break reminder, so it is sent once per break.
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
    [$this->user, $this->profile, $this->patient] = remote_patient();
    $this->doctorId = (string) $this->w['doctor']->id;
});

it('tells each waiting walk-in patient once, in-app only, when the break reaches its expected end', function () {
    walkin_arrived($this->w, $this->staff, $this->patient, $this->profile);
    fresh_session();
    presence_post('break/start', $this->w['doctorHeaders'], ['expected_minutes' => 15])->assertOk();
    $session = DB::table('doctor_presence_sessions')->first();
    $end = $this->clock->at->addMinutes(15);

    $this->clock->at = $end;
    $run = fn () => (new SendBreakReminder($session->id, $end->toIso8601ZuluString()))->handle(app(SendsNotifications::class), $this->clock, app(BreakOverrunNotices::class));
    $run();

    $notices = DB::table('notifications')->where('notifiable_id', $this->user->id)->where('type', 'walkins.doctor_delayed')->get();
    expect($notices)->toHaveCount(1)
        ->and(json_decode($notices[0]->data, true))->toMatchArray(['title' => 'The doctor is running late', 'body' => 'Your estimate has been updated.']);

    $run();
    expect(DB::table('notifications')->where('notifiable_id', $this->user->id)->where('type', 'walkins.doctor_delayed')->count())->toBe(1);
});
