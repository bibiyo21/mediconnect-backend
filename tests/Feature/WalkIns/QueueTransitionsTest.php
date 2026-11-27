<?php

use App\Modules\Notifications\Events\NotificationCreated;
use App\Modules\Notifications\Jobs\SendPushNotification;
use App\Modules\WalkIns\Events\WalkInEntryUpdated;
use App\Shared\Logging\ScrubSensitiveLogData;
use App\Shared\Logging\SentryScrubber;
use Carbon\CarbonImmutable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;

/*
 * Increment 5.6-2: recall, skip, no-show, return and priority (plan D9 to D12, D15). Every illegal move is 409 INVALID_STATE_TRANSITION with
 * the current status; the consultation made at the call follows the entry; events and audit rows carry codes and ids only.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    $this->a = wi_guest($this->staff, $this->svc, 'Alpha Guest');
    $this->queue = wi_queue_id();
});

function wi_cstatus(string $entry): ?string
{
    return DB::table('consultations')->where('walkin_queue_entry_id', $entry)->value('status');
}

it('recalls a called patient: the count grows, nothing changes by itself, and the limit refuses at the policy value (D9)', function () {
    wi_call_next($this->queue, $this->staff)->assertOk();

    wi_post("{$this->a}/recall", $this->staff)->assertOk()->assertJsonPath('data.recall_count', 1)->assertJsonPath('data.status', 'CALLED');
    wi_post("{$this->a}/recall", $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.recall_count', 2);
    wi_post("{$this->a}/recall", $this->staff)->assertStatus(409)->assertJsonPath('code', 'RECALL_LIMIT_REACHED');

    $events = DB::table('walkin_queue_events')->where('event_type', 'RECALLED')->orderBy('id')->get();
    expect($events)->toHaveCount(2)->and(json_decode($events[1]->metadata, true))->toBe(['recall_count' => 2])->and($events[0]->from_status)->toBe('CALLED')->and($events[0]->to_status)->toBe('CALLED')
        ->and(wi_status($this->a))->toBe('CALLED')->and(DB::table('walkin_queue_entries')->where('id', $this->a)->value('recall_count'))->toBe(2)
        ->and(DB::table('audit_logs')->where('action', 'walkins.recalled')->count())->toBe(2);
});

it('reads the recall limit from the doctor\'s walk-in policy at the location', function () {
    walkin_policy($this->w['manager'], $this->w['clinic'], $this->w['assignment'], ['recall_limit' => 1])->assertOk();
    wi_call_next($this->queue, $this->staff)->assertOk();

    wi_post("{$this->a}/recall", $this->staff)->assertOk()->assertJsonPath('data.recall_count', 1);
    wi_post("{$this->a}/recall", $this->staff)->assertStatus(409)->assertJsonPath('code', 'RECALL_LIMIT_REACHED');
});

it('sends a generic recall notice to a registered patient', function () {
    [$user, $profile, $headers] = remote_patient();
    $id = register_walkin($headers, patient_body($this->svc, $profile))->assertCreated()->json('data.id');
    wi_post("{$id}/arrive", $this->staff)->assertOk();
    wi_post("{$this->a}/cancel", $this->staff, ['reason_code' => 'OTHER', 'patient_informed_in_person' => true])->assertOk();
    wi_call_next($this->queue, $this->staff)->assertOk();

    wi_post("{$id}/recall", $this->staff)->assertOk();

    $data = json_decode(DB::table('notifications')->where('type', 'walkins.recalled')->sole()->data, true);
    expect($data['title'])->toBe("Reminder: it's your turn")->and($data['body'])->toBe('Open the app for details.')->and(array_keys($data))->toEqualCanonicalizing(['entry_id', 'title', 'body']);
});

it('skips a called patient: the consultation is cancelled, the doctor assignment is cleared, and a fresh call revives it with a new recall count (D3, D4, D9)', function () {
    wi_call_next($this->queue, $this->staff)->assertOk();
    wi_post("{$this->a}/recall", $this->staff)->assertOk();
    $consultation = DB::table('consultations')->where('walkin_queue_entry_id', $this->a)->value('id');

    wi_post("{$this->a}/skip", $this->staff)->assertOk()->assertJsonPath('data.status', 'SKIPPED');
    $row = DB::table('walkin_queue_entries')->where('id', $this->a)->first();
    expect(wi_cstatus($this->a))->toBe('CANCELLED')->and($row->assigned_doctor_profile_id)->toBeNull()
        ->and(DB::table('walkin_queue_events')->where('event_type', 'SKIPPED')->sole()->from_status)->toBe('CALLED');

    wi_post("{$this->a}/return", $this->staff)->assertOk();
    wi_call_next($this->queue, $this->staff)->assertOk();
    expect(DB::table('consultations')->where('walkin_queue_entry_id', $this->a)->count())->toBe(1)->and(DB::table('consultations')->where('walkin_queue_entry_id', $this->a)->value('id'))->toBe($consultation)
        ->and(wi_cstatus($this->a))->toBe('SCHEDULED')->and(DB::table('walkin_queue_entries')->where('id', $this->a)->value('recall_count'))->toBe(0);
});

it('marks a no-show from SKIPPED at any time and from CALLED only at the recall limit (D9)', function () {
    wi_call_next($this->queue, $this->staff)->assertOk();

    wi_post("{$this->a}/no-show", $this->staff)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonPath('errors.status.0', 'CALLED');
    wi_post("{$this->a}/recall", $this->staff)->assertOk();
    wi_post("{$this->a}/no-show", $this->staff)->assertStatus(409);
    wi_post("{$this->a}/recall", $this->staff)->assertOk();
    wi_post("{$this->a}/no-show", $this->staff)->assertOk()->assertJsonPath('data.status', 'NO_SHOW');
    expect(wi_cstatus($this->a))->toBe('CANCELLED')->and(DB::table('walkin_queue_entries')->where('id', $this->a)->value('assigned_doctor_profile_id'))->toBeNull()
        ->and(DB::table('walkin_queue_events')->where('event_type', 'NO_SHOW')->sole()->from_status)->toBe('CALLED');

    $b = wi_guest($this->staff, $this->svc, 'Bravo Guest');
    wi_call_next($this->queue, $this->staff)->assertOk();
    wi_post("{$b}/skip", $this->staff)->assertOk();
    wi_post("{$b}/no-show", $this->staff)->assertOk()->assertJsonPath('data.status', 'NO_SHOW');
    wi_post("{$b}/return", $this->staff)->assertStatus(409)->assertJsonPath('errors.status.0', 'NO_SHOW');
});

it('gives a patient a generic notice for skip and no-show', function () {
    [$user, $profile, $headers] = remote_patient();
    $id = register_walkin($headers, patient_body($this->svc, $profile))->assertCreated()->json('data.id');
    wi_post("{$id}/arrive", $this->staff)->assertOk();
    wi_post("{$this->a}/cancel", $this->staff, ['reason_code' => 'OTHER', 'patient_informed_in_person' => true])->assertOk();
    wi_call_next($this->queue, $this->staff)->assertOk();
    wi_post("{$id}/skip", $this->staff)->assertOk();
    wi_post("{$id}/no-show", $this->staff)->assertOk();

    foreach (['walkins.skipped', 'walkins.no_show'] as $type) {
        $notice = DB::table('notifications')->where('type', $type)->sole();
        $data = json_decode($notice->data, true);
        expect($notice->notifiable_id)->toBe($user->id)->and(array_keys($data))->toEqualCanonicalizing(['entry_id', 'title', 'body'])
            ->and(strtolower($data['title'].' '.$data['body']))->not->toContain('doctor')->not->toContain('clinic number')->not->toContain($this->clock->now()->format('Y'));
    }
});

it('returns a skipped patient to WAITING at the end of their group, keeping arrived_at (D10)', function () {
    $b = wi_guest($this->staff, $this->svc, 'Bravo Guest');
    wi_call_next($this->queue, $this->staff)->assertOk();
    wi_post("{$this->a}/skip", $this->staff)->assertOk();
    $arrived = DB::table('walkin_queue_entries')->where('id', $this->a)->value('arrived_at');
    wi_advance(7);

    wi_post("{$this->a}/return", $this->staff)->assertOk()->assertJsonPath('data.status', 'WAITING');

    $row = DB::table('walkin_queue_entries')->where('id', $this->a)->first();
    expect($row->arrived_at)->toBe($arrived)->and(CarbonImmutable::parse($row->queue_sort_at)->equalTo($this->clock->now()))->toBeTrue()
        ->and(DB::table('walkin_queue_events')->where('event_type', 'RETURNED')->sole()->from_status)->toBe('SKIPPED')
        ->and(DB::table('audit_logs')->where('action', 'walkins.returned')->count())->toBe(1)
        ->and(wi_call_next($this->queue, $this->staff)->json('data.id'))->toBe($b);
});

it('refuses to return on a closed queue or one of another day, and refuses a patient who re-registers while skipped', function () {
    [, $profile, $headers] = remote_patient();
    $id = register_walkin($headers, patient_body($this->svc, $profile))->assertCreated()->json('data.id');
    wi_post("{$id}/arrive", $this->staff)->assertOk();
    wi_post("{$this->a}/cancel", $this->staff, ['reason_code' => 'OTHER', 'patient_informed_in_person' => true])->assertOk();
    wi_call_next($this->queue, $this->staff)->assertOk();
    wi_post("{$id}/skip", $this->staff)->assertOk();

    register_walkin($headers, patient_body($this->svc, $profile))->assertStatus(409)->assertJsonPath('code', 'ENTRY_ALREADY_LIVE')->assertJsonPath('errors.entry_id.0', $id);

    DB::table('walkin_queues')->where('id', $this->queue)->update(['status' => 'CLOSED', 'closed_at' => now()]);
    wi_post("{$id}/return", $this->staff)->assertStatus(409)->assertJsonPath('code', 'QUEUE_CLOSED')->assertJsonPath('reason_code', 'closed');
    DB::table('walkin_queues')->where('id', $this->queue)->update(['status' => 'PAUSED', 'closed_at' => null]);
    wi_post("{$id}/return", $this->staff)->assertOk(); // paused is not closed
    wi_post("{$id}/skip", $this->staff)->assertStatus(409); // WAITING: skip is for CALLED only
});

it('changes priority on WAITING and SKIPPED entries only, with a reason code, and stores codes only in the event and audit row (D12)', function () {
    $b = wi_guest($this->staff, $this->svc, 'Bravo Guest');

    wi_post("{$b}/priority", $this->staff, ['priority_level' => 'URGENT'])->assertStatus(422)->assertJsonValidationErrorFor('priority_reason_code', 'errors');
    wi_post("{$b}/priority", $this->staff, ['priority_level' => 'NORMAL', 'priority_reason_code' => 'OTHER'])->assertStatus(422);
    wi_post("{$b}/priority", $this->staff, ['priority_level' => 'HIGH', 'priority_reason_code' => 'OTHER'])->assertStatus(422);
    wi_post("{$b}/priority", $this->staff, ['priority_level' => 'URGENT', 'priority_reason_code' => 'PREGNANT', 'priority_note' => 'needs the quiet room'])->assertOk()
        ->assertJsonPath('data.priority_level', 'URGENT')->assertJsonPath('data.priority_reason_code', 'PREGNANT');

    $event = DB::table('walkin_queue_events')->where('event_type', 'PRIORITY_CHANGED')->sole();
    $audit = DB::table('audit_logs')->where('action', 'walkins.priority_changed')->sole();
    expect(json_decode($event->metadata, true))->toEqual(['from' => 'NORMAL', 'to' => 'URGENT', 'reason_code' => 'PREGNANT'])
        ->and(json_encode([$event, $audit]))->not->toContain('quiet room');
    // The urgent entry is now the head, ahead of the earlier normal one.
    expect(wi_call_next($this->queue, $this->staff)->json('data.id'))->toBe($b);

    wi_post("{$b}/priority", $this->staff, ['priority_level' => 'NORMAL'])->assertStatus(409)->assertJsonPath('errors.status.0', 'CALLED');
    wi_post("{$b}/skip", $this->staff)->assertOk();
    wi_post("{$b}/priority", $this->staff, ['priority_level' => 'NORMAL'])->assertOk()->assertJsonPath('data.priority_level', 'NORMAL')->assertJsonPath('data.priority_reason_code', null);
    $remote = remote_patient();
    $r = register_walkin($remote[2], patient_body($this->svc, $remote[1]))->assertCreated()->json('data.id');
    wi_post("{$r}/priority", $this->staff, ['priority_level' => 'PRIORITY', 'priority_reason_code' => 'SENIOR_PWD'])->assertStatus(409)->assertJsonPath('errors.status.0', 'REGISTERED');
});

it('says 409 INVALID_STATE_TRANSITION with the current status for every illegal move', function () {
    // status of the target => the operations that are illegal from it.
    $illegal = [
        'WAITING' => ['recall', 'skip', 'no-show', 'return'],
        'REGISTERED' => ['call', 'recall', 'skip', 'no-show', 'return'],
        'CALLED' => ['return'],
        'IN_CONSULTATION' => ['call', 'recall', 'skip', 'no-show', 'return', 'priority'],
        'COMPLETED' => ['call', 'recall', 'skip', 'no-show', 'return', 'priority'],
        'NO_SHOW' => ['call', 'recall', 'skip', 'no-show', 'return', 'priority'],
        'CANCELLED' => ['call', 'recall', 'skip', 'no-show', 'return', 'priority'],
        'SKIPPED' => ['call', 'recall', 'skip'],
    ];
    $n = 0;
    foreach ($illegal as $status => $operations) {
        foreach ($operations as $op) {
            [, $id] = wi_second_queue_for_doctor($this->w, 'WAITING', 'Z'.chr(65 + intdiv($n, 26)).chr(65 + $n++ % 26));
            $extra = match ($status) {
                'CALLED' => ['assigned_doctor_profile_id' => $this->w['doctor']->id, 'called_at' => now()],
                'IN_CONSULTATION' => ['assigned_doctor_profile_id' => $this->w['doctor']->id, 'consultation_started_at' => now()],
                'COMPLETED' => ['completed_at' => now()],
                'REGISTERED' => ['queue_sort_at' => null, 'arrived_at' => null],
                default => [],
            };
            DB::table('walkin_queue_entries')->where('id', $id)->update(['status' => $status, ...$extra]);

            $r = wi_post("{$id}/{$op}", $this->staff, $op === 'priority' ? ['priority_level' => 'NORMAL'] : []);

            expect($r->status())->toBe(409, "{$status} {$op}")->and($r->json('code'))->toBe('INVALID_STATE_TRANSITION', "{$status} {$op}")->and($r->json('errors.status.0'))->toBe($status, "{$status} {$op}");
            // Nothing moved.
            expect(wi_status($id))->toBe($status);
            DB::table('walkin_queue_entries')->where('id', $id)->update(['status' => 'CANCELLED', 'assigned_doctor_profile_id' => null]);
        }
    }
});

it('keeps a forced failure during a call out of the response, the log and Sentry and rolls everything back', function () {
    config(['logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'tap' => [ScrubSensitiveLogData::class]]]);
    config(['logging.default' => 'capture']);
    Log::forgetChannel('capture');
    $secret = 'Quillon Fairweather';
    $guest = wi_triaged($this->staff, $this->svc, $secret);
    // A registered patient at the head: their hint and notice must not go out for a call that failed.
    [, $profile, $headers] = remote_patient();
    $patient = register_walkin($headers, patient_body($this->svc, $profile))->assertCreated()->json('data.id');
    wi_post("{$patient}/arrive", $this->staff)->assertOk();
    DB::table('walkin_queue_entries')->where('id', $patient)->update(['triage_priority' => 9, 'triage_reason_code' => 'STAFF_CONCERN', 'queue_sort_at' => now()->subHour()]);
    DB::table('walkin_queue_entries')->where('id', $guest)->update(['triage_priority' => 0, 'triage_reason_code' => null, 'triage_note_encrypted' => null]);
    Illuminate\Support\Facades\Event::fake([WalkInEntryUpdated::class, NotificationCreated::class]);
    Queue::fake();
    $pdo = new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "uq_one_called_entry_per_queue"'."\nDETAIL:  Key (guest_name)=({$secret}) already exists.");
    $failure = new QueryException('pgsql', 'update "walkin_queue_entries" set "status" = ? where guest_name = ?', ['CALLED', $secret, '+639179998888'], $pdo, ['host' => 'postgres']);
    DB::listen(function ($query) use ($failure) {
        if (str_starts_with($query->sql, 'update "walkin_queue_entries" set "status"')) {
            throw $failure;
        }
    });

    $r = wi_call_next($this->queue, $this->staff)->assertStatus(500);

    expect($r->getContent())->not->toContain('Quillon')->not->toContain('Fairweather')->not->toContain('9179998888');
    Log::error($failure->getMessage(), ['exception' => $failure, 'context' => ['guest' => ['name' => $secret]]]);
    /** @var TestHandler $handler */
    $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
    $logged = json_encode(array_map(fn ($rec) => [$rec->message, $rec->context, $rec->extra], $handler->getRecords()));
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag($failure)]);
    $sent = SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => $failure]));
    foreach (['Quillon', 'Fairweather', '9179998888'] as $word) {
        expect($logged)->not->toContain($word)->and($sent->getExceptions()[0]->getValue())->not->toContain($word);
    }
    Illuminate\Support\Facades\Event::assertNotDispatched(WalkInEntryUpdated::class);
    Illuminate\Support\Facades\Event::assertNotDispatched(NotificationCreated::class);
    Queue::assertNotPushed(SendPushNotification::class);
    expect(wi_status($guest))->toBe('WAITING')->and(wi_status($patient))->toBe('WAITING')->and(DB::table('consultations')->count())->toBe(0)->and(DB::table('walkin_queue_events')->where('event_type', 'CALLED')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'walkins.called')->count())->toBe(0);
    $this->app['events']->forget('Illuminate\Database\Events\QueryExecuted');
});

it('keeps an existing priority note when the level changes without a new one', function () {
    wi_post("{$this->a}/priority", $this->staff, ['priority_level' => 'PRIORITY', 'priority_reason_code' => 'SENIOR_PWD', 'priority_note' => 'wheelchair'])->assertOk();
    wi_post("{$this->a}/priority", $this->staff, ['priority_level' => 'URGENT', 'priority_reason_code' => 'STAFF_CONCERN'])->assertOk();
    expect(wi_json("/api/v1/walk-ins/{$this->a}", $this->staff)->json('data.priority_note'))->toBe('wheelchair');

    wi_post("{$this->a}/priority", $this->staff, ['priority_level' => 'NORMAL'])->assertOk();
    expect(DB::table('walkin_queue_entries')->where('id', $this->a)->value('triage_note_encrypted'))->toBeNull();
});

it('refuses a priority change for a purged guest or on a queue of another day, and a recall on a closed or past-day queue', function () {
    wi_call_next($this->queue, $this->staff)->assertOk();
    $b = wi_guest($this->staff, $this->svc, 'Bravo Guest');
    DB::table('walkin_queue_entries')->where('id', $b)->update(['guest_name_encrypted' => null, 'guest_phone_encrypted' => null, 'guest_phone_hash' => null, 'guest_pii_purged_at' => now()]);
    wi_post("{$b}/priority", $this->staff, ['priority_level' => 'URGENT', 'priority_reason_code' => 'OTHER'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    $c = wi_guest($this->staff, $this->svc, 'Charlie Guest');
    $yesterday = $this->clock->now()->setTimezone('Asia/Manila')->subDay()->toDateString();
    $today = $this->clock->now()->setTimezone('Asia/Manila')->toDateString();
    DB::table('walkin_queues')->where('id', $this->queue)->update(['local_date' => $yesterday]);
    wi_post("{$c}/priority", $this->staff, ['priority_level' => 'URGENT', 'priority_reason_code' => 'OTHER'])->assertStatus(409)->assertJsonPath('reason_code', 'not_today');
    wi_post("{$this->a}/recall", $this->staff)->assertStatus(409)->assertJsonPath('reason_code', 'not_today');
    DB::table('walkin_queues')->where('id', $this->queue)->update(['local_date' => $today, 'status' => 'CLOSED', 'closed_at' => now()]);
    wi_post("{$this->a}/recall", $this->staff)->assertStatus(409)->assertJsonPath('code', 'QUEUE_CLOSED')->assertJsonPath('reason_code', 'closed');
    // Cleanup moves stay possible on an old queue.
    DB::table('walkin_queues')->where('id', $this->queue)->update(['local_date' => $yesterday, 'status' => 'OPEN', 'closed_at' => null]);
    wi_post("{$this->a}/skip", $this->staff)->assertOk();
    wi_post("{$this->a}/no-show", $this->staff)->assertOk();
});
