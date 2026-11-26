<?php

use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Notifications\Jobs\TerminateRealtimeConnections;
use App\Modules\Notifications\Realtime\ChannelAuthorizer;
use App\Modules\Notifications\Realtime\RealtimeEvent;
use App\Modules\Patients\Actions\ManageDependents;
use App\Modules\Patients\Models\Dependent;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Events\WalkInEntryUpdated;
use App\Modules\WalkIns\Events\WalkInQueueUpdated;
use App\Modules\WalkIns\Events\WalkInRegistered;
use App\Modules\WalkIns\Jobs\EmitQueueUpdated;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
 * Increment 5.5a-3: walk-in real-time hints (decisions 266-272). Events are captured as queued BroadcastEvent jobs.
 */

beforeEach(function () {
    Queue::fake();
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    Queue::fake(); // forget the set-up traffic
});

/** @return list<array{event: RealtimeEvent, name: string, channels: list<string>, payload: array<string, mixed>}> */
function wi_emitted(): array
{
    return Queue::pushed(BroadcastEvent::class)->map(fn ($job) => [
        'event' => $job->event,
        'name' => $job->event->broadcastAs(),
        'channels' => array_map(fn ($c) => $c->name, $job->event->broadcastOn()),
        'payload' => $job->event->broadcastWith(),
    ])->values()->all();
}

function wi_emitted_named(string $name): array
{
    return array_values(array_filter(wi_emitted(), fn ($e) => $e['name'] === $name));
}

it('sends registered, queue and entry hints with ids, labels, statuses and counts only', function () {
    [$user, $profile, $headers] = remote_patient();
    register_walkin($headers, patient_body($this->svc, $profile))->assertCreated();
    $entry = DB::table('walkin_queue_entries')->value('id');
    wi_post("{$entry}/arrive", $this->staff)->assertOk();
    wi_post("{$entry}/cancel", $this->staff, ['reason_code' => 'PATIENT_REQUEST'])->assertOk();
    register_walkin($this->staff, guest_body($this->svc, 'Juan Dela Cruz'))->assertCreated();
    $queueId = (string) WalkInQueue::query()->value('id');
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/walk-ins/queues/{$queueId}/pause", [], [...$this->staff, 'Idempotency-Key' => (string) Str::uuid7()])->assertOk();

    $events = wi_emitted();
    expect($events)->not->toBeEmpty();
    $json = json_encode($events[0]['payload']);
    foreach ($events as $e) {
        $keys = array_keys($e['payload']);
        $class = get_class($e['event']);
        // Exactly the whitelist, no extra and no missing key; none looks like personal data; scalar or null values.
        expect($keys)->toEqualCanonicalizing($class::ALLOWED_KEYS);
        foreach ($e['payload'] as $key => $value) {
            expect(preg_match('/name|note|diagnos|prescri|membership|token|phone|email|address/i', $key))->toBe(0)
                ->and($value === null || is_scalar($value))->toBeTrue();
        }
        $all = json_encode($e['payload']);
        expect($all)->not->toContain('Juan')->not->toContain('Dela Cruz')->not->toContain('0917')->not->toContain('+639');
    }
    expect($json)->toBeString();
});

it('does not let a subclass send a key outside its whitelist or a forbidden key', function () {
    foreach ([WalkInRegistered::class, WalkInQueueUpdated::class, WalkInEntryUpdated::class] as $class) {
        foreach ($class::ALLOWED_KEYS as $key) {
            expect(preg_match('/name|note|diagnos|prescri|membership|token|phone|email|address/i', $key))->toBe(0);
        }
    }
});

it('sends a registration to the location queue channel and the queue doctor channel only', function () {
    [$user, $profile, $headers] = remote_patient();
    register_walkin($headers, patient_body($this->svc, $profile))->assertCreated();
    $queue = WalkInQueue::query()->sole();

    $registered = wi_emitted_named('WalkInRegistered');
    expect($registered)->toHaveCount(1)
        ->and($registered[0]['channels'])->toEqual(["presence-clinic.{$this->w['location']}.queue", "presence-doctor.{$this->w['doctor']->id}"])
        ->and($registered[0]['payload']['queue_id'])->toBe($queue->id)
        ->and($registered[0]['payload']['status'])->toBe('REGISTERED')
        ->and($registered[0]['payload']['waiting_count'])->toBe(1);
});

it('keeps another doctor and another location out of the audience', function () {
    $second = wi_second_location($this->w);
    $secondStaff = staff_of($this->w, Role::ClinicStaff, $second);
    register_walkin($this->staff, guest_body($this->svc))->assertCreated();

    foreach (wi_emitted() as $e) {
        expect($e['channels'])->not->toContain("presence-clinic.{$second}.queue");
        foreach ($e['channels'] as $channel) {
            expect($channel)->toBeIn(["presence-clinic.{$this->w['location']}.queue", "presence-doctor.{$this->w['doctor']->id}"]);
        }
    }
    // Staff of the other location is refused the queue channel, as the channel matrix already proves.
    $staffUser = User::query()->find(DB::table('clinic_members')->where('clinic_location_id', $second)->value('user_id'));
    expect($staffUser)->not->toBeNull()
        ->and(app(ChannelAuthorizer::class)->clinicQueue($staffUser, $this->w['location']))->toBeFalse();
    expect($secondStaff)->toBeArray();
});

it('sends a pool queue (no doctor) only to the clinic queue channel', function () {
    $event = new WalkInQueueUpdated('q', 'loc', null, 'OPEN', 0, 1, '2026-01-01T00:00:00Z');
    $channels = array_map(fn ($c) => $c->name, $event->broadcastOn());

    expect($channels)->toBe(['presence-clinic.loc.queue']);
});

it('tells a patient about their own entry on their own channel, and a guest gets no patient hint', function () {
    [$user, $profile, $headers] = remote_patient();
    register_walkin($headers, patient_body($this->svc, $profile))->assertCreated();
    register_walkin($this->staff, guest_body($this->svc))->assertCreated();

    $hints = wi_emitted_named('WalkInEntryUpdated');
    expect($hints)->toHaveCount(1)
        ->and($hints[0]['channels'])->toBe(["presence-user.{$user->id}"])
        ->and(array_keys($hints[0]['payload']))->toEqual(['entry_id', 'status', 'queue_version', 'occurred_at']);

    $entry = DB::table('walkin_queue_entries')->where('patient_profile_id', $profile)->value('id');
    wi_post("{$entry}/arrive", $this->staff)->assertOk();
    expect(collect(wi_emitted_named('WalkInEntryUpdated'))->last()['payload']['status'])->toBe('WAITING')
        ->and(wi_emitted_named('WalkInEntryUpdated'))->toHaveCount(2);
});

it('resolves the audience at dispatch: a revoked guardian gets no further entry hint, a current one does', function () {
    [$guardian, $guardianProfile, $headers] = remote_patient();
    $dependant = wi_dependent($headers);
    register_walkin($headers, patient_body($this->svc, $dependant))->assertCreated();
    $entry = DB::table('walkin_queue_entries')->where('patient_profile_id', $dependant)->value('id');

    $first = wi_emitted_named('WalkInEntryUpdated');
    expect($first)->toHaveCount(1)->and($first[0]['channels'])->toBe(["presence-user.{$guardian->id}"]);

    $link = Dependent::query()->where('dependent_patient_profile_id', $dependant)->firstOrFail();
    app('auth')->forgetGuards();
    $this->deleteJson("/api/v1/patients/me/dependents/{$dependant}", [], $headers)->assertSuccessful();
    expect($link->refresh()->authorization_status->value)->toBe('REVOKED');

    wi_post("{$entry}/arrive", $this->staff)->assertOk();
    // Staff still get queue hints; the revoked guardian gets nothing more.
    expect(wi_emitted_named('WalkInEntryUpdated'))->toHaveCount(1)
        ->and(wi_emitted_named('WalkInQueueUpdated'))->not->toBeEmpty();
});

it('terminates the guardian\'s connections once on revocation, after commit and never on a rollback', function () {
    config(['broadcasting.default' => 'reverb']); // the null driver holds no connections, so terminating is a no-op there
    [$guardian, $guardianProfile, $headers] = remote_patient();
    $dependant = wi_dependent($headers);
    $link = Dependent::query()->where('dependent_patient_profile_id', $dependant)->firstOrFail();
    $profile = PatientProfile::query()->findOrFail($guardianProfile);
    try {
        DB::transaction(function () use ($link, $profile) {
            app(ManageDependents::class)->remove($link, $profile);
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }
    Queue::assertNotPushed(TerminateRealtimeConnections::class);
    expect($link->refresh()->authorization_status->value)->toBe('AUTHORIZED');

    app('auth')->forgetGuards();
    $this->deleteJson("/api/v1/patients/me/dependents/{$dependant}", [], $headers)->assertSuccessful();
    Queue::assertPushed(TerminateRealtimeConnections::class, 1);
    Queue::assertPushed(TerminateRealtimeConnections::class, fn ($job) => $job->userId === $guardian->id);
});

it('debounces queue hints per queue: one now, one trailing, none extra', function () {
    config(['queue.default' => 'redis']); // on the sync queue the trailing hint is skipped
    $queue = WalkInQueue::query()->first() ?? (function () {
        register_walkin($this->staff, guest_body($this->svc))->assertCreated();

        return WalkInQueue::query()->sole();
    })();
    Queue::fake(); // forget what registration sent
    $realtime = app(QueueRealtime::class);

    $realtime->queueChanged($queue);
    $realtime->queueChanged($queue);
    $realtime->queueChanged($queue);
    $realtime->queueChanged($queue);

    expect(wi_emitted_named('WalkInQueueUpdated'))->toHaveCount(1);
    Queue::assertPushed(EmitQueueUpdated::class, 1);

    // The trailing job sends one more hint with the state at that moment.
    (new EmitQueueUpdated($queue->id))->handle($realtime);
    expect(wi_emitted_named('WalkInQueueUpdated'))->toHaveCount(2);
});

it('sends nothing for a change that rolls back', function () {
    register_walkin($this->staff, guest_body($this->svc))->assertCreated();
    $queue = WalkInQueue::query()->sole();
    Queue::fake();

    try {
        DB::transaction(function () use ($queue) {
            app(QueueRealtime::class)->queueChanged($queue);
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNothingPushed();
});

it('keeps the realtime-events contract file in step with the event classes', function () {
    $doc = json_decode((string) file_get_contents(base_path('openapi/realtime-events.json')), true);
    $samples = [
        WalkInRegistered::class => new WalkInRegistered('q', 'loc', 'doc', 'e', 'A-01', 'WAITING', 1, 2, '2026-01-01T00:00:00Z'),
        WalkInQueueUpdated::class => new WalkInQueueUpdated('q', 'loc', 'doc', 'OPEN', 1, 2, '2026-01-01T00:00:00Z'),
        WalkInEntryUpdated::class => new WalkInEntryUpdated('u', 'p', 'e', 'WAITING', 2, '2026-01-01T00:00:00Z'),
    ];
    $types = ['string' => 'is_string', 'integer' => 'is_int'];

    foreach ($samples as $class => $event) {
        $spec = $doc['events'][class_basename($class)];
        $payload = $event->broadcastWith();
        expect(array_keys($spec['payload']))->toEqual(array_keys($payload))->toEqualCanonicalizing($class::ALLOWED_KEYS);
        foreach ($spec['payload'] as $key => $type) {
            $nullable = str_ends_with($type, '|null');
            $check = $types[str_replace('|null', '', $type)];
            expect($check($payload[$key]) || ($nullable && $payload[$key] === null))->toBeTrue();
        }
        // WalkInEntryUpdated re-checks its audience at publish time, so its channels are covered by the revocation test; the others are plain.
        $channels = $class === WalkInEntryUpdated::class ? ['presence-user.u'] : array_map(fn ($c) => $c->name, $event->broadcastOn());
        $declared = array_map(fn ($c) => str_replace(['{locationId}', '{doctorProfileId}', '{userId}'], ['loc', 'doc', 'u'], $c), $spec['channels']);
        expect($channels)->toEqual($declared);
        expect($event->broadcastAs())->toBe(class_basename($class));
    }
});

it('lists every RealtimeEvent under app/Modules in the contract file, with its channel kind and exact keys', function () {
    $doc = json_decode((string) file_get_contents(base_path('openapi/realtime-events.json')), true);
    $found = [];
    foreach ((new Finder)->files()->in(app_path('Modules'))->name('*.php') as $file) {
        $source = $file->getContents();
        if (preg_match('/^namespace\s+([^;]+);.*?\bclass\s+(\w+)\s+extends\s+RealtimeEvent\b/ms', $source, $m) === 1) {
            $found[$m[2]] = $m[1].'\\'.$m[2];
        }
    }

    expect($found)->not->toBeEmpty()
        ->and(array_keys($doc['events']))->toEqualCanonicalizing(array_keys($found));
    foreach ($found as $name => $class) {
        expect(array_keys($doc['events'][$name]['payload']))->toEqualCanonicalizing($class::ALLOWED_KEYS)
            ->and($doc['events'][$name]['channels'])->each->toStartWith('presence-');
    }
});

it('checks the audience again at publish time: a hint queued before a revocation reaches no one', function () {
    [$guardian, , $headers] = remote_patient();
    $dependant = wi_dependent($headers);
    register_walkin($headers, patient_body($this->svc, $dependant))->assertCreated();

    $hint = wi_emitted_named('WalkInEntryUpdated')[0]['event'];
    expect(array_map(fn ($c) => $c->name, $hint->broadcastOn()))->toBe(["presence-user.{$guardian->id}"])
        ->and(array_keys($hint->broadcastWith()))->not->toContain('patient_profile_id');

    DB::table('dependents')->where('dependent_patient_profile_id', $dependant)->update(['authorization_status' => 'REVOKED', 'revoked_at' => now()]);

    expect($hint->broadcastOn())->toBe([]);
});

it('never breaks a committed write when a hint fails: a cache failure inside the callback still gives 2xx', function () {
    register_walkin($this->staff, guest_body($this->svc))->assertCreated();
    $queueId = (string) WalkInQueue::query()->value('id');
    Queue::fake();
    Cache::partialMock()->shouldReceive('add')->withArgs(fn ($key) => str_starts_with((string) $key, 'walkin:rt:'))->andThrow(new RuntimeException('cache down'));
    Cache::partialMock()->shouldReceive('add')->passthru();
    Log::spy();

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/walk-ins/queues/{$queueId}/pause", [], [...$this->staff, 'Idempotency-Key' => (string) Str::uuid7()])->assertOk();

    expect(DB::table('walkin_queues')->where('id', $queueId)->value('status'))->toBe('PAUSED');
    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'walkins.realtime_hint_dropped' && $c === ['queue_id' => $queueId, 'exception' => RuntimeException::class])->once();
});

it('pushes no WalkInRegistered when the registration rolls back after the hint was prepared', function () {
    [, $profile, $headers] = remote_patient();
    $this->mock(SendsNotifications::class)->shouldReceive('notify')->andThrow(new RuntimeException('notify failed'));
    Queue::fake();

    register_walkin($headers, patient_body($this->svc, $profile))->assertStatus(500);

    expect(DB::table('walkin_queue_entries')->count())->toBe(0)
        ->and(wi_emitted())->toBe([]);
});

it('puts a change made between the leading emit and the trailing job into the trailing payload', function () {
    config(['queue.default' => 'redis']);
    register_walkin($this->staff, guest_body($this->svc))->assertCreated();
    $queue = WalkInQueue::query()->sole();
    Queue::fake();
    $realtime = app(QueueRealtime::class);

    $realtime->queueChanged($queue); // leading: OPEN
    DB::table('walkin_queues')->where('id', $queue->id)->update(['status' => 'PAUSED']);
    $realtime->queueChanged($queue); // inside the window: trailing job scheduled
    (new EmitQueueUpdated($queue->id))->handle($realtime);

    $hints = wi_emitted_named('WalkInQueueUpdated');
    expect($hints)->toHaveCount(2)
        ->and($hints[0]['payload']['status'])->toBe('OPEN')
        ->and($hints[1]['payload']['status'])->toBe('PAUSED');
});

it('skips the trailing hint on the sync queue (a delayed job would run at once)', function () {
    config(['queue.default' => 'sync']);
    register_walkin($this->staff, guest_body($this->svc))->assertCreated();
    $queue = WalkInQueue::query()->sole();
    Queue::fake();

    app(QueueRealtime::class)->queueChanged($queue);
    app(QueueRealtime::class)->queueChanged($queue);

    expect(wi_emitted_named('WalkInQueueUpdated'))->toHaveCount(1);
    Queue::assertNotPushed(EmitQueueUpdated::class);
});
