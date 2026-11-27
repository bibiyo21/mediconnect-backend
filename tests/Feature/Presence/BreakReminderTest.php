<?php

use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Presence\Jobs\SendBreakReminder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Increment 5.3: the break reminder is a delayed job on the notifications queue that tells the doctor
 * their announced break has ended (architecture.md §9.5). It acts only for the same, still running break.
 */

beforeEach(function () {
    seed_roles();
    fake_messages();
    $this->clock = pin_clock();
    $this->w = presence_world();
    $this->doc = $this->w['doctor'];
    $this->headers = $this->w['doctorHeaders'];
    $this->run = function (string $sessionId, string $end) {
        (new SendBreakReminder($sessionId, $end))->handle(app(SendsNotifications::class), $this->clock);
    };
    $this->reminders = fn () => DB::table('notifications')->where('notifiable_id', $this->doc->user_id)->where('type', 'presence.break_ended')->get();
});

it('queues a delayed reminder on the notifications queue at the expected end, after commit', function () {
    Queue::fake();
    checkin($this->headers, $this->w['location'])->assertCreated();

    presence_post('break/start', $this->headers, ['expected_minutes' => 25])->assertOk();

    $end = $this->clock->at->addMinutes(25);
    Queue::assertPushedOn('notifications', SendBreakReminder::class, fn (SendBreakReminder $job) => $job->delay->getTimestamp() === $end->getTimestamp()
        && $job->expectedEndAt === $end->toIso8601ZuluString() && $job->afterCommit === true);
    Queue::assertPushed(SendBreakReminder::class, 1);
});

it('notifies the doctor with generic text once the break has run to its expected end', function () {
    checkin($this->headers, $this->w['location'])->assertCreated();
    presence_post('break/start', $this->headers, ['expected_minutes' => 15])->assertOk(); // the sync queue runs the job at once: not yet time
    $session = DB::table('doctor_presence_sessions')->first();
    $end = $this->clock->at->addMinutes(15);
    expect(($this->reminders)())->toHaveCount(0);

    $this->clock->at = $end;
    ($this->run)($session->id, $end->toIso8601ZuluString());

    $note = ($this->reminders)();
    expect($note)->toHaveCount(1)
        ->and(json_decode($note[0]->data, true))->toEqual(['clinic_location_id' => $this->w['location'], 'title' => 'Your break time is up', 'body' => 'Your planned break has ended. Open the app to update your status.']);

    // A retried or duplicate run adds nothing.
    ($this->run)($session->id, $end->toIso8601ZuluString());
    expect(($this->reminders)())->toHaveCount(1);
});

it('does nothing when the break ended early, was restarted, or the session is closed', function () {
    checkin($this->headers, $this->w['location'])->assertCreated();
    presence_post('break/start', $this->headers, ['expected_minutes' => 15])->assertOk();
    $session = DB::table('doctor_presence_sessions')->first();
    $firstEnd = $this->clock->at->addMinutes(15)->toIso8601ZuluString();

    // Ended early.
    $this->clock->at = $this->clock->at->addMinutes(5);
    presence_post('break/end', $this->headers)->assertOk();
    $this->clock->at = $this->clock->at->addMinutes(20);
    ($this->run)($session->id, $firstEnd);
    expect(($this->reminders)())->toHaveCount(0);

    // Restarted: the first job's end no longer matches the running break.
    presence_post('break/start', $this->headers, ['expected_minutes' => 30])->assertOk();
    $this->clock->at = $this->clock->at->addMinutes(40);
    ($this->run)($session->id, $firstEnd);
    expect(($this->reminders)())->toHaveCount(0);

    // Closed session.
    DB::table('doctor_presence_sessions')->where('id', $session->id)->update(['current_status' => 'OUT', 'current_break_expected_end_at' => null, 'checked_out_at' => $this->clock->at, 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_by' => $this->doc->user_id, 'checked_out_method' => 'DOCTOR_APP']);
    ($this->run)($session->id, $this->clock->at->toIso8601ZuluString());
    expect(($this->reminders)())->toHaveCount(0);
});

it('does nothing for an unknown session', function () {
    ($this->run)('018f1c3e-7b1a-7000-8000-000000000001', $this->clock->at->toIso8601ZuluString());

    expect(($this->reminders)())->toHaveCount(0);
});

it('sets the dedupe mark only after the notice exists, so a failed run is retried', function () {
    checkin($this->headers, $this->w['location'])->assertCreated();
    presence_post('break/start', $this->headers, ['expected_minutes' => 15])->assertOk();
    $session = DB::table('doctor_presence_sessions')->first();
    $end = $this->clock->at->addMinutes(15);
    $this->clock->at = $end;

    $failing = new class implements SendsNotifications
    {
        public function notify(string $userId, string $type, string $title, string $body, array $data = [], bool $push = true): string
        {
            throw new RuntimeException('database hiccup');
        }
    };
    expect(fn () => (new SendBreakReminder($session->id, $end->toIso8601ZuluString()))->handle($failing, $this->clock))->toThrow(RuntimeException::class);
    expect(Cache::has("presence:break-reminder:{$session->id}:{$end->getTimestamp()}"))->toBeFalse();

    // The retry goes through and then marks the break as done.
    ($this->run)($session->id, $end->toIso8601ZuluString());
    expect(($this->reminders)())->toHaveCount(1);
});

it('writes the notice rows together or not at all', function () {
    checkin($this->headers, $this->w['location'])->assertCreated();
    presence_post('break/start', $this->headers, ['expected_minutes' => 15])->assertOk();
    $session = DB::table('doctor_presence_sessions')->first();
    $end = $this->clock->at->addMinutes(15);
    $this->clock->at = $end;

    $half = new class implements SendsNotifications
    {
        public function notify(string $userId, string $type, string $title, string $body, array $data = [], bool $push = true): string
        {
            DB::table('notifications')->insert(['id' => (string) Str::uuid7(), 'type' => $type, 'notifiable_type' => 'x', 'notifiable_id' => $userId, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

            throw new RuntimeException('second insert failed');
        }
    };

    expect(fn () => (new SendBreakReminder($session->id, $end->toIso8601ZuluString()))->handle($half, $this->clock))->toThrow(RuntimeException::class)
        ->and(($this->reminders)())->toHaveCount(0);
});

it('does nothing when the session is ON_BREAK but records no expected end', function () {
    checkin($this->headers, $this->w['location'])->assertCreated();
    presence_post('break/start', $this->headers, ['expected_minutes' => 15])->assertOk();
    $session = DB::table('doctor_presence_sessions')->first();
    $end = $this->clock->at->addMinutes(15);
    DB::table('doctor_presence_sessions')->where('id', $session->id)->update(['current_break_expected_end_at' => null]);
    $this->clock->at = $end;

    ($this->run)($session->id, $end->toIso8601ZuluString());

    expect(($this->reminders)())->toHaveCount(0);
});
