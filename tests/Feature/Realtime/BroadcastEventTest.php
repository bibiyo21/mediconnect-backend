<?php

use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\RealtimeConfigGuard;
use App\Modules\Notifications\Realtime\RealtimeEvent;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestRealtimeEvent;

/*
 * Increment 5.2: broadcasts are hints, queued after commit on the notifications
 * queue (phase 5 plan Q12), on presence channels only, with a whitelisted payload.
 */

const EVENT_LOCATION = '018f1c3e-7b1a-7000-8000-000000000001';

it('queues a broadcast on the notifications queue', function () {
    Queue::fake();

    TestRealtimeEvent::dispatch(EVENT_LOCATION, 3);

    Queue::assertPushedOn('notifications', BroadcastEvent::class);
});

it('publishes only after the surrounding transaction commits and never when it rolls back', function () {
    Queue::fake();

    try {
        DB::transaction(function () {
            TestRealtimeEvent::dispatch(EVENT_LOCATION, 1);
            Queue::assertNothingPushed(); // still inside the transaction
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }
    Queue::assertNothingPushed();

    DB::transaction(fn () => TestRealtimeEvent::dispatch(EVENT_LOCATION, 2));
    Queue::assertPushed(BroadcastEvent::class, 1);
});

it('names the event for clients, carries only the whitelisted payload and uses presence channels', function () {
    $event = new TestRealtimeEvent(EVENT_LOCATION, 4);

    expect($event->broadcastAs())->toBe('TestRealtimeEvent')
        ->and($event->broadcastWith())->toBe(['location_id' => EVENT_LOCATION, 'waiting' => 4])
        ->and($event->broadcastQueue())->toBe('notifications')
        ->and($event->tries)->toBe(3)
        ->and(collect($event->broadcastOn())->every(fn ($channel) => $channel instanceof PresenceChannel))->toBeTrue();
});

it('sends nothing unless a key is in ALLOWED_KEYS (the default list is empty)', function () {
    $event = new class extends RealtimeEvent
    {
        protected function channels(): array
        {
            return [Channels::user('018f1c3e-7b1a-7000-8000-000000000001')];
        }

        protected function payload(): array
        {
            return ['waiting' => 3];
        }
    };

    expect(fn () => $event->broadcastWith())->toThrow(LogicException::class, 'not in ALLOWED_KEYS');
});

it('refuses payload keys that look like personal or clinical data even when listed', function (string $key) {
    $event = new class($key) extends RealtimeEvent
    {
        public const ALLOWED_KEYS = ['patient_name', 'note', 'diagnosis', 'prescription_id', 'membership_no', 'push_token', 'phone', 'email', 'home_address', 'Contact_Phone', 'queue_number'];

        public function __construct(private string $key) {}

        protected function channels(): array
        {
            return [Channels::user('018f1c3e-7b1a-7000-8000-000000000001')];
        }

        protected function payload(): array
        {
            return [$this->key => 'x'];
        }
    };

    expect(fn () => $event->broadcastWith())->toThrow(LogicException::class, 'looks like personal or clinical data');
})->with(['patient_name', 'note', 'diagnosis', 'prescription_id', 'membership_no', 'push_token', 'phone', 'email', 'home_address', 'Contact_Phone']);

it('allows ids, counts and queue numbers, and refuses non-scalar values', function () {
    $ok = new class extends RealtimeEvent
    {
        public const ALLOWED_KEYS = ['entry_id', 'queue_number', 'waiting', 'status', 'detail'];

        protected function channels(): array
        {
            return [Channels::user('018f1c3e-7b1a-7000-8000-000000000001')];
        }

        protected function payload(): array
        {
            return ['entry_id' => '018f1c3e-7b1a-7000-8000-000000000002', 'queue_number' => 'A-014', 'waiting' => 3, 'status' => null];
        }
    };
    expect($ok->broadcastWith())->toHaveKeys(['entry_id', 'queue_number', 'waiting', 'status']);

    $bad = new class extends RealtimeEvent
    {
        public const ALLOWED_KEYS = ['detail'];

        protected function channels(): array
        {
            return [Channels::user('018f1c3e-7b1a-7000-8000-000000000001')];
        }

        protected function payload(): array
        {
            return ['detail' => ['nested' => 'array']];
        }
    };
    expect(fn () => $bad->broadcastWith())->toThrow(LogicException::class, 'must be a scalar or null');
});

it('refuses public channels and plain private channels (a private subscriber has no user id and cannot be terminated)', function (Channel $channel) {
    $event = new class($channel) extends RealtimeEvent
    {
        public function __construct(private Channel $channel) {}

        protected function channels(): array
        {
            return [$this->channel];
        }

        protected function payload(): array
        {
            return [];
        }
    };

    expect(fn () => $event->broadcastOn())->toThrow(LogicException::class, 'presence channels only');
})->with([
    'public' => fn () => new Channel('doctor-availability.x'),
    'plain private' => fn () => new PrivateChannel('user.018f1c3e-7b1a-7000-8000-000000000001'),
]);

it('does not let a subclass override the safety checks', function () {
    $broadcastOn = new ReflectionMethod(RealtimeEvent::class, 'broadcastOn');
    $broadcastWith = new ReflectionMethod(RealtimeEvent::class, 'broadcastWith');

    expect($broadcastOn->isFinal())->toBeTrue()->and($broadcastWith->isFinal())->toBeTrue();
});

it('refuses a wildcard origin list in production with the reverb driver only', function () {
    expect(fn () => RealtimeConfigGuard::assertSafe('production', 'reverb', ['*']))->toThrow(RuntimeException::class, 'REVERB_ALLOWED_ORIGINS');

    RealtimeConfigGuard::assertSafe('production', 'reverb', ['app.example.ph']);
    RealtimeConfigGuard::assertSafe('production', 'null', ['*']);
    RealtimeConfigGuard::assertSafe('local', 'reverb', ['*']);
    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['localhost', '127.0.0.1'])
        ->and(config('reverb.apps.apps.0.rate_limiting.enabled'))->toBeTrue();
});

it('spells channel names in one place', function () {
    $id = EVENT_LOCATION;

    expect(Channels::user($id)->name)->toBe("presence-user.{$id}")
        ->and(Channels::doctor($id)->name)->toBe("presence-doctor.{$id}")
        ->and(Channels::clinic($id)->name)->toBe("presence-clinic.{$id}")
        ->and(Channels::clinicQueue($id)->name)->toBe("presence-clinic.{$id}.queue");
});

it('reports realtime as unavailable instead of approving everything when no signing broadcaster is configured', function (string $driver) {
    seed_roles();
    config(['broadcasting.default' => $driver]);
    $user = patient_account();

    channel_auth("presence-user.{$user->id}", signed_in($user))->assertStatus(503)->assertJsonPath('code', 'DEPENDENCY_UNAVAILABLE');
})->with(['null', 'log']);
