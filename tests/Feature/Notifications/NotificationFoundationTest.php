<?php

use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Notifications\Contracts\PushGateway;
use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Notifications\Events\NotificationCreated;
use App\Modules\Notifications\Jobs\SendPushNotification;
use App\Modules\Notifications\Push\DisabledPushGateway;
use App\Modules\Notifications\Push\LogPushGateway;
use App\Modules\Notifications\Push\PushConfigGuard;
use App\Modules\Users\Models\User;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/*
 * Increment 5.3, notification foundation (phase 5 plan Q11, Q12): an in-app row written in the
 * caller's transaction, a NotificationCreated hint on private-user after commit, and a push through
 * the log driver with generic text only. Real FCM and APNs wait for owner-provided credentials.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->notify = fn (array $data = []) => app(SendsNotifications::class)->notify($this->user->id, 'presence.break_ended', 'Your break time is up', 'Your planned break has ended.', $data);
    $this->deviceWithToken = function (string $token = 'fcm-token-1', ?User $user = null): void {
        $device = new UserDevice(['platform' => 'ANDROID']);
        $device->user()->associate($user ?? $this->user)->save();
        DB::table('device_tokens')->insert(['id' => (string) Str::uuid7(), 'user_device_id' => $device->id, 'provider' => 'FCM', 'token' => $token, 'created_at' => now(), 'updated_at' => now()]);
    };
});

it('stores an in-app notification with generic text and the delivery rows', function () {
    Queue::fake();

    $id = ($this->notify)(['clinic_location_id' => '018f1c3e-7b1a-7000-8000-000000000001']);

    $row = DB::table('notifications')->where('id', $id)->first();
    expect($row->notifiable_id)->toBe($this->user->id)->and($row->type)->toBe('presence.break_ended')->and($row->read_at)->toBeNull()
        ->and(json_decode($row->data, true))->toEqual(['clinic_location_id' => '018f1c3e-7b1a-7000-8000-000000000001', 'title' => 'Your break time is up', 'body' => 'Your planned break has ended.'])
        ->and(DB::table('notification_deliveries')->where('notification_id', $id)->orderBy('id')->get(['channel', 'status'])->map(fn ($d) => $d->channel.':'.$d->status)->all())->toBe(['IN_APP:SENT', 'PUSH:QUEUED']);
});

it('broadcasts NotificationCreated on private-user only, with ids and no text, and pushes on the notifications queue', function () {
    Queue::fake();

    $id = ($this->notify)();

    $events = Queue::pushed(BroadcastEvent::class, fn ($job) => $job->event instanceof NotificationCreated)->map->event;
    expect($events)->toHaveCount(1)
        ->and(collect($events[0]->broadcastOn())->map->name->all())->toBe(["private-user.{$this->user->id}"])
        ->and($events[0]->broadcastWith())->toBe(['notification_id' => $id, 'type' => 'presence.break_ended'])
        ->and($events[0]->broadcastQueue())->toBe('notifications');
    Queue::assertPushedOn('notifications', SendPushNotification::class, fn ($job) => $job->notificationId === $id && $job->afterCommit === true);
});

it('keeps the row and the hint all-or-nothing with the surrounding transaction', function () {
    Queue::fake();

    try {
        DB::transaction(function () {
            ($this->notify)();
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('notifications')->count())->toBe(0);
    Queue::assertNotPushed(BroadcastEvent::class);
});

it('skips the push when the user has no device token', function () {
    $id = ($this->notify)(); // the sync queue runs the push job after commit

    expect(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->value('status'))->toBe('SKIPPED');
});

it('records the push through the log driver as SKIPPED (never SENT), logging no text or token', function () {
    Log::spy();
    ($this->deviceWithToken)('fcm-secret-token');

    $id = ($this->notify)();

    $delivery = DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->first();
    expect($delivery->status)->toBe('SKIPPED')->and($delivery->provider)->toBe('log')->and($delivery->sent_at)->toBeNull()->and($delivery->error)->toContain('No push provider');
    Log::shouldHaveReceived('info')->with('Push notification (log driver)', ['notification_id' => $id, 'type' => 'presence.break_ended', 'devices' => 1])->once();
    Log::shouldNotHaveReceived('info', fn ($message, $context = []) => str_contains(json_encode($context), 'fcm-secret-token') || str_contains(json_encode($context), 'planned break'));
});

it('does not push when the user switched push off for that event type, and never twice', function () {
    ($this->deviceWithToken)();
    DB::table('notification_preferences')->insert(['user_id' => $this->user->id, 'event_type' => 'presence.break_ended', 'channel' => 'PUSH', 'enabled' => false]);

    $id = ($this->notify)();
    expect(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->value('status'))->toBe('SKIPPED');

    // Running the job again for a delivery that is no longer QUEUED does nothing.
    Log::spy();
    (new SendPushNotification($id))->handle(app(PushGateway::class));
    Log::shouldNotHaveReceived('info');
});

it('ignores revoked devices when pushing', function () {
    ($this->deviceWithToken)();
    DB::table('user_devices')->where('user_id', $this->user->id)->update(['revoked_at' => now()]);

    $id = ($this->notify)();

    expect(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->value('status'))->toBe('SKIPPED');
});

/** A gateway that really "delivers" (returns true), or fails, to exercise the job's states. */
function recording_gateway(bool $delivers = true, bool $fails = false): PushGateway
{
    return new class($delivers, $fails) implements PushGateway
    {
        public int $calls = 0;

        /** @var list<string> */
        public array $collapseKeys = [];

        public function __construct(private readonly bool $delivers, private readonly bool $fails) {}

        public function send(string $notificationId, string $type, string $title, string $body, array $deviceTokens, string $collapseKey): bool
        {
            $this->calls++;
            $this->collapseKeys[] = $collapseKey;
            if ($this->fails) {
                throw new RuntimeException('provider down');
            }

            return $this->delivers;
        }
    };
}

it('marks a push SENT only when a provider reports delivery', function () {
    ($this->deviceWithToken)();
    $gateway = recording_gateway(true);
    app()->instance(PushGateway::class, $gateway);

    $id = ($this->notify)();

    $delivery = DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->first();
    expect($delivery->status)->toBe('SENT')->and($delivery->sent_at)->not->toBeNull()->and($gateway->calls)->toBe(1);
});

it('claims the delivery atomically: a push already claimed or finished is not sent again', function () {
    ($this->deviceWithToken)();
    Queue::fake();
    $id = ($this->notify)();
    $gateway = recording_gateway(true);

    // Another worker holds a fresh claim.
    DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->update(['status' => 'SENDING', 'claimed_at' => now()->subMinutes(2)]);
    (new SendPushNotification($id))->handle($gateway);
    expect($gateway->calls)->toBe(0)->and(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->value('status'))->toBe('SENDING');

    // Back to QUEUED: exactly one run claims it, the next finds nothing to do.
    DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->update(['status' => 'QUEUED']);
    (new SendPushNotification($id))->handle($gateway);
    (new SendPushNotification($id))->handle($gateway);
    expect($gateway->calls)->toBe(1)->and(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->value('status'))->toBe('SENT');
});

it('puts a failed push back to QUEUED for the retry', function () {
    ($this->deviceWithToken)();
    Queue::fake();
    $id = ($this->notify)();

    expect(fn () => (new SendPushNotification($id))->handle(recording_gateway(true, true)))->toThrow(RuntimeException::class, 'provider down');

    expect(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->value('status'))->toBe('QUEUED');
});

it('binds the push gateway by config, and refuses unknown drivers and the log driver in production', function () {
    config(['services.push.driver' => 'log']);
    app()->forgetInstance(PushGateway::class);
    expect(app(PushGateway::class))->toBeInstanceOf(LogPushGateway::class);

    config(['services.push.driver' => 'disabled']);
    app()->forgetInstance(PushGateway::class);
    expect(app(PushGateway::class))->toBeInstanceOf(DisabledPushGateway::class)->and(app(PushGateway::class)->send('n', 't', 'x', 'y', ['tok'], 'n'))->toBeFalse();

    config(['services.push.driver' => 'fcm']);
    app()->forgetInstance(PushGateway::class);
    expect(fn () => app(PushGateway::class))->toThrow(InvalidArgumentException::class, 'Unknown PUSH_DRIVER [fcm]');

    PushConfigGuard::assertSafe('local', 'log');
    PushConfigGuard::assertSafe('production', 'disabled');
    expect(fn () => PushConfigGuard::assertSafe('production', 'log'))->toThrow(RuntimeException::class, 'refused in production')
        ->and(fn () => PushConfigGuard::assertSafe('local', 'fcm'))->toThrow(RuntimeException::class, 'Unknown PUSH_DRIVER');
});

it('stamps claimed_at on the claim and takes over a claim older than the configured window', function () {
    ($this->deviceWithToken)();
    Queue::fake();
    $id = ($this->notify)();
    $delivery = fn () => DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->first();
    expect($delivery()->claimed_at)->toBeNull();

    // A worker died after claiming: 11 minutes old, over the 10-minute default.
    DB::table('notification_deliveries')->where('id', $delivery()->id)->update(['status' => 'SENDING', 'claimed_at' => now()->subMinutes(11)]);
    $gateway = recording_gateway(true);
    (new SendPushNotification($id))->handle($gateway);

    expect($gateway->calls)->toBe(1)->and($delivery()->status)->toBe('SENT');

    // The window is configurable.
    config(['services.push.reclaim_after_minutes' => 30]);
    $second = ($this->notify)();
    DB::table('notification_deliveries')->where('notification_id', $second)->where('channel', 'PUSH')->update(['status' => 'SENDING', 'claimed_at' => now()->subMinutes(11)]);
    (new SendPushNotification($second))->handle($gateway);
    expect($gateway->calls)->toBe(1);
});

it('stamps claimed_at while sending, and clears it when the retry may claim again', function () {
    ($this->deviceWithToken)();
    Queue::fake();
    $id = ($this->notify)();
    $seen = null;
    $gateway = new class($seen) implements PushGateway
    {
        public function __construct(public mixed &$claimedAt) {}

        public function send(string $notificationId, string $type, string $title, string $body, array $deviceTokens, string $collapseKey): bool
        {
            $this->claimedAt = DB::table('notification_deliveries')->where('notification_id', $notificationId)->where('channel', 'PUSH')->first(['status', 'claimed_at']);

            throw new RuntimeException('provider down');
        }
    };

    expect(fn () => (new SendPushNotification($id))->handle($gateway))->toThrow(RuntimeException::class);

    expect($seen->status)->toBe('SENDING')->and($seen->claimed_at)->not->toBeNull();
    $row = DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->first();
    expect($row->status)->toBe('QUEUED')->and($row->claimed_at)->toBeNull();
});

it('marks the delivery FAILED and counts the attempt after the final try, but leaves a finished one alone', function () {
    ($this->deviceWithToken)();
    Queue::fake();
    $id = ($this->notify)();

    (new SendPushNotification($id))->failed(new RuntimeException('provider down'));
    $row = DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->first();
    expect($row->status)->toBe('FAILED')->and($row->attempts)->toBe(1)->and($row->error)->toBe(RuntimeException::class);

    $sent = ($this->notify)();
    DB::table('notification_deliveries')->where('notification_id', $sent)->where('channel', 'PUSH')->update(['status' => 'SENT']);
    (new SendPushNotification($sent))->failed(new RuntimeException('late'));
    expect(DB::table('notification_deliveries')->where('notification_id', $sent)->where('channel', 'PUSH')->value('status'))->toBe('SENT');
});

it('never puts a delivery back to QUEUED once the provider has answered', function () {
    ($this->deviceWithToken)();
    Queue::fake();
    $id = ($this->notify)();
    $gateway = recording_gateway(true);

    (new SendPushNotification($id))->handle($gateway);

    $row = DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->first();
    expect($row->status)->toBe('SENT')->and($row->claimed_at)->not->toBeNull();
    (new SendPushNotification($id))->handle($gateway);
    expect($gateway->calls)->toBe(1);
});

it('defaults the push driver to disabled in config, so a production boot with PUSH_DRIVER unset never throws', function () {
    // The test run sets PUSH_DRIVER=log (phpunit.xml), so the default is read from the config file itself.
    expect(file_get_contents(base_path('config/services.php')))->toContain("env('PUSH_DRIVER', 'disabled')");

    PushConfigGuard::assertSafe('production', 'disabled');
    expect(file_get_contents(base_path('.env.example')))->toContain('PUSH_DRIVER=log');
});

it('passes the notification id to the provider as its collapse key', function () {
    ($this->deviceWithToken)();
    $gateway = recording_gateway(true);
    app()->instance(PushGateway::class, $gateway);

    $id = ($this->notify)();

    expect($gateway->collapseKeys)->toBe([$id]);
});

/** A queue job double, to see what the push job asks the queue to do. */
function fake_queue_job(): MockInterface
{
    $job = Mockery::mock(Job::class);
    $job->shouldIgnoreMissing();

    return $job;
}

it('releases a run that loses to a live claim, until that claim would expire, instead of completing', function () {
    ($this->deviceWithToken)();
    Queue::fake();
    $id = ($this->notify)();
    DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->update(['status' => 'SENDING', 'claimed_at' => now()->subMinutes(2)]);
    $gateway = recording_gateway(true);
    $queueJob = fake_queue_job();
    // 10-minute window, claimed 2 minutes ago: about 8 minutes (480 s) left.
    $queueJob->shouldReceive('release')->once()->with(Mockery::on(fn ($seconds) => $seconds >= 478 && $seconds <= 483));
    $job = new SendPushNotification($id);
    $job->setJob($queueJob);

    $job->handle($gateway);

    expect($gateway->calls)->toBe(0)->and(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->value('status'))->toBe('SENDING');
});

it('does not release a run that finds the delivery already finished', function () {
    ($this->deviceWithToken)();
    Queue::fake();
    $id = ($this->notify)();
    DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->update(['status' => 'SENT']);
    $queueJob = fake_queue_job();
    $queueJob->shouldNotReceive('release');
    $job = new SendPushNotification($id);
    $job->setJob($queueJob);

    $job->handle(recording_gateway(true));
});

it('takes over a stale SENDING row on a later run, after the live claim expired', function () {
    ($this->deviceWithToken)();
    Queue::fake();
    $id = ($this->notify)();
    $row = fn () => DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->first();
    DB::table('notification_deliveries')->where('id', $row()->id)->update(['status' => 'SENDING', 'claimed_at' => now()->subMinutes(2)]);
    $gateway = recording_gateway(true);
    $first = new SendPushNotification($id);
    $first->setJob(fake_queue_job());
    $first->handle($gateway); // released: the claim is live
    expect($gateway->calls)->toBe(0);

    $this->travel(9)->minutes(); // the claim is now 11 minutes old
    (new SendPushNotification($id))->handle($gateway);

    expect($gateway->calls)->toBe(1)->and($row()->status)->toBe('SENT')->and($gateway->collapseKeys)->toBe([$id]);
});

it('lives until a deadline instead of counting releases as attempts, and times out far below the reclaim window', function () {
    $job = new SendPushNotification('018f1c3e-7b1a-7000-8000-000000000001');

    expect($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addMinutes(20)->getTimestamp() - 5) // 2 x 10-minute window + margin
        ->and(isset($job->tries))->toBeFalse()
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->timeout)->toBeLessThanOrEqual(30)
        ->and($job->timeout)->toBeLessThan(60); // below the shortest allowed reclaim window (1 minute)
});

it('does not mark a delivery FAILED when the provider already answered and only finishing the row failed', function () {
    ($this->deviceWithToken)();
    Queue::fake();
    $id = ($this->notify)();
    $row = fn () => DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->first();
    $gateway = recording_gateway(true);

    // Writing the final SENT status fails (the provider call already succeeded).
    DB::beforeExecuting(function ($sql, $bindings) {
        if (str_starts_with($sql, 'update "notification_deliveries"') && in_array('SENT', $bindings, true)) {
            throw new RuntimeException('database went away');
        }
    });
    $job = new SendPushNotification($id);
    expect(fn () => $job->handle($gateway))->toThrow(RuntimeException::class, 'database went away');

    // The queue gave up: failed() runs. The push may be out, so the row is not FAILED and not requeued.
    $job->failed(new RuntimeException('database went away'));
    expect($gateway->calls)->toBe(1)->and($row()->status)->toBe('SENDING')->and($row()->provider_called_at)->not->toBeNull();
});
