<?php

use App\Modules\Rbac\Enums\Role;
use App\Modules\WalkIns\Jobs\PurgeGuestWalkInPii;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Shared\Logging\RedactSensitiveData;
use App\Shared\Logging\ScrubSensitiveLogData;
use App\Shared\Logging\SentryScrubber;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;

/*
 * Increment 5.5a: guest and registration data stays where it belongs. A guest's name and phone, the HMO name as typed and a priority
 * note are encrypted at rest, never logged, never sent to Sentry, never copied into audit rows, events, notices or broadcasts, and a
 * daily job erases them after the retention period (A5, decision 103).
 */

const WI_NAME = 'Zelda Quirke';
const WI_PHONE_LOCAL = '09179998888';
const WI_HMO = 'Zeta Gold Card';
const WI_NOTE = 'needs the quiet room';

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    config(['logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'tap' => [ScrubSensitiveLogData::class]]]);
    config(['logging.default' => 'capture']);
    Log::forgetChannel('capture');
});

function wi_logged(): string
{
    /** @var TestHandler $handler */
    $handler = Log::channel('capture')->getLogger()->getHandlers()[0];

    return json_encode(array_map(fn ($r) => [$r->message, $r->context, $r->extra], $handler->getRecords()));
}

function wi_guest_data(): array
{
    return [WI_NAME, 'Quirke', WI_PHONE_LOCAL, '9179998888', WI_HMO, WI_NOTE];
}

it('keeps a guest\'s data out of logs, audit rows, events, notices and outbox through a whole day of use', function () {
    $r = register_walkin($this->staff, guest_body($this->svc, WI_NAME, ['guest' => ['name' => WI_NAME, 'phone' => WI_PHONE_LOCAL], 'payer_type' => 'HMO', 'hmo_card_name_text' => WI_HMO,
        'priority_level' => 'PRIORITY', 'priority_reason_code' => 'OTHER', 'priority_note' => WI_NOTE]))->assertCreated();
    $id = $r->json('data.id');
    foreach (['zelda', '0917 999 8888', 'A-01'] as $term) {
        wi_search($this->w['location'], $term, $this->staff)->assertOk();
    }
    wi_json("/api/v1/walk-ins/{$id}", $this->staff)->assertOk()->assertJsonPath('data.guest_phone', '+639179998888');
    wi_json("/api/v1/walk-ins/queue?clinic_location_id={$this->w['location']}", session_for(account(Role::Admin)))->assertOk();
    wi_post("{$id}/cancel", $this->staff, ['reason_code' => 'PATIENT_LEFT', 'patient_informed_in_person' => true])->assertOk();

    $stored = json_encode([
        DB::table('audit_logs')->get(), DB::table('walkin_queue_events')->get(), DB::table('notifications')->get(), DB::table('security_events')->get(),
        DB::table('outbox_events')->get(), DB::table('walkin_queues')->get(),
    ]);
    $logged = wi_logged();
    foreach (wi_guest_data() as $secret) {
        expect($stored)->not->toContain($secret)->and($logged)->not->toContain($secret);
    }
    // Encrypted at rest: the entry row holds ciphertext only.
    $row = (string) json_encode(DB::table('walkin_queue_entries')->get());
    foreach (wi_guest_data() as $secret) {
        expect($row)->not->toContain($secret);
    }
    expect(DB::table('audit_logs')->whereIn('action', ['walkins.search'])->count())->toBe(3)
        ->and(DB::table('audit_logs')->where('action', 'walkins.entry_read')->count())->toBe(1);
});

it('never serializes the encrypted columns of an entry', function () {
    register_walkin($this->staff, guest_body($this->svc, WI_NAME, ['payer_type' => 'HMO', 'hmo_card_name_text' => WI_HMO, 'priority_level' => 'PRIORITY', 'priority_reason_code' => 'OTHER', 'priority_note' => WI_NOTE]))->assertCreated();

    $array = WalkInQueueEntry::query()->firstOrFail()->toArray();
    expect(array_keys($array))->not->toContain('guest_name_encrypted')->not->toContain('guest_phone_encrypted')->not->toContain('guest_phone_hash')
        ->not->toContain('triage_note_encrypted')->not->toContain('hmo_card_name_encrypted')->not->toContain('idempotency_key')
        ->and(json_encode($array))->not->toContain('Quirke')->not->toContain(WI_HMO);
});

it('keeps a forced failure carrying a guest name out of the response, the log and Sentry, and rolls everything back', function () {
    $pdo = new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "uq_queue_number"'."\nDETAIL:  Key (guest_name)=(".WI_NAME.') already exists.');
    $failure = new QueryException('pgsql', 'insert into "walkin_queue_entries" ("guest_name", "guest_phone", "hmo_card_name") values (?, ?, ?)', [WI_NAME, '+639179998888', WI_HMO], $pdo, ['host' => 'postgres']);
    DB::listen(function ($query) use ($failure) {
        if (str_starts_with($query->sql, 'insert into "walkin_queue_entries"')) {
            throw $failure;
        }
    });

    $key = (string) Str::uuid7();
    $r = register_walkin($this->staff, guest_body($this->svc, WI_NAME, ['guest' => ['name' => WI_NAME, 'phone' => WI_PHONE_LOCAL], 'payer_type' => 'HMO', 'hmo_card_name_text' => WI_HMO]), $key)->assertStatus(500);

    expect($r->json('code'))->toBe('SERVER_ERROR')->and($r->getContent())->not->toContain('Zelda')->not->toContain('Quirke')->not->toContain('9179998888')->not->toContain(WI_HMO);
    // The exception handler hands the failure to Sentry (below); anything that logs it goes through the scrubbing channel.
    Log::error($failure->getMessage(), ['exception' => $failure, 'context' => ['guest' => ['name' => WI_NAME]]]);
    $logged = wi_logged();
    expect($logged)->toContain('SQLSTATE[23505]');
    foreach (wi_guest_data() as $secret) {
        expect($logged)->not->toContain($secret);
    }

    // Sentry gets the same exception through its scrubber.
    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag($failure)]);
    $sent = SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => $failure]));
    foreach (wi_guest_data() as $secret) {
        expect($sent->getExceptions()[0]->getValue())->not->toContain($secret);
    }

    // Nothing was kept: no queue, no entry, no event, no audit row, and the key is free for a retry.
    expect(DB::table('walkin_queues')->count())->toBe(0)->and(DB::table('walkin_queue_entries')->count())->toBe(0)->and(DB::table('walkin_queue_events')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'walkins.registered')->count())->toBe(0)->and(DB::table('idempotency_keys')->where('key', $key)->count())->toBe(0);
    $this->app['events']->forget('Illuminate\Database\Events\QueryExecuted');
});

it('redacts the new sensitive keys wherever they appear in a log record or a Sentry event', function () {
    $record = (new RedactSensitiveData)(new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'registering priority_note=needs-the-quiet-room', [
        'guest' => ['name' => WI_NAME, 'phone' => WI_PHONE_LOCAL], 'guest_name' => WI_NAME, 'guest_phone' => WI_PHONE_LOCAL, 'hmo_card_name_text' => WI_HMO,
        'priority_note' => WI_NOTE, 'triage_note' => WI_NOTE, 'request' => ['guest' => ['name' => WI_NAME], 'hmo_card_name_text' => WI_HMO], 'is_guest' => true, 'guest_count' => 3,
    ]));
    $json = json_encode([$record->message, $record->context]);

    foreach ([WI_NAME, WI_PHONE_LOCAL, WI_HMO, WI_NOTE, 'needs-the-quiet-room'] as $secret) {
        expect($json)->not->toContain($secret);
    }
    // Words that only look alike are kept.
    expect($record->context['is_guest'])->toBeTrue()->and($record->context['guest_count'])->toBe(3);

    $event = Event::createEvent();
    $event->setExtra(['guest' => ['name' => WI_NAME], 'hmo_card_name_text' => WI_HMO, 'priority_note' => WI_NOTE, 'queue_id' => 'abc']);
    $extra = SentryScrubber::beforeSend($event)->getExtra();
    expect(json_encode($extra))->not->toContain('Zelda')->not->toContain(WI_HMO)->not->toContain(WI_NOTE)->and($extra['queue_id'])->toBe('abc');
});

// The purge (A5, decision 103).

it('erases a guest\'s data after the retention period and keeps the entry, its number, status and events', function () {
    $key = register_walkin($this->staff, guest_body($this->svc, WI_NAME, ['payer_type' => 'HMO', 'hmo_card_name_text' => WI_HMO, 'priority_level' => 'URGENT', 'priority_reason_code' => 'PREGNANT', 'priority_note' => WI_NOTE]))->assertCreated()->json('data.id');
    $recent = register_walkin($this->staff, guest_body($this->svc, 'Recent Guest', ['guest' => ['name' => 'Recent Guest', 'phone' => '09170000009']]))->assertCreated()->json('data.id');
    DB::table('walkin_queue_entries')->where('id', $key)->update(['created_at' => $this->clock->now()->subDays(31)]);
    $events = DB::table('walkin_queue_events')->count();

    (new PurgeGuestWalkInPii)->handle($this->clock);

    $old = DB::table('walkin_queue_entries')->where('id', $key)->sole();
    expect($old->guest_name_encrypted)->toBeNull()->and($old->guest_phone_encrypted)->toBeNull()->and($old->guest_phone_hash)->toBeNull()->and($old->hmo_card_name_encrypted)->toBeNull()
        ->and($old->triage_note_encrypted)->toBeNull()->and($old->guest_pii_purged_at)->not->toBeNull()
        ->and($old->queue_number)->toBe(1)->and($old->status)->toBe('WAITING')->and($old->triage_reason_code)->toBe('PREGNANT')->and($old->privacy_notice_version)->toBe('v1');
    $kept = DB::table('walkin_queue_entries')->where('id', $recent)->sole();
    expect($kept->guest_name_encrypted)->not->toBeNull()->and($kept->guest_pii_purged_at)->toBeNull()->and(DB::table('walkin_queue_events')->count())->toBe($events);

    // After the purge the entry shows no name, and the search cannot find it.
    $detail = wi_json("/api/v1/walk-ins/{$key}", $this->staff)->assertOk();
    expect($detail->json('data.name'))->toBeNull()->and($detail->json('data.guest_phone'))->toBeNull();
    wi_search($this->w['location'], 'zelda', $this->staff)->assertOk()->assertJsonCount(0, 'data');
});

it('leaves patient entries and fresh guests alone, honours the configured retention, and is safe to repeat', function () {
    [, $profile, $headers] = remote_patient();
    $patient = register_walkin($headers, patient_body($this->svc, $profile, ['payer_type' => 'HMO', 'hmo_card_name_text' => 'Maxicare']))->assertCreated()->json('data.id');
    $guest = register_walkin($this->staff, guest_body($this->svc))->assertCreated()->json('data.id');
    DB::table('walkin_queue_entries')->update(['created_at' => $this->clock->now()->subDays(10)]);

    (new PurgeGuestWalkInPii)->handle($this->clock);
    expect(DB::table('walkin_queue_entries')->where('id', $guest)->value('guest_pii_purged_at'))->toBeNull(); // 10 days is inside the 30 day period

    config(['walkins.guest_pii_retention_days' => 5]);
    (new PurgeGuestWalkInPii)->handle($this->clock);
    (new PurgeGuestWalkInPii)->handle($this->clock);
    $purgedAt = DB::table('walkin_queue_entries')->where('id', $guest)->value('guest_pii_purged_at');
    expect($purgedAt)->not->toBeNull()
        ->and(DB::table('walkin_queue_entries')->where('id', $patient)->value('hmo_card_name_encrypted'))->not->toBeNull()
        ->and(DB::table('walkin_queue_entries')->where('id', $patient)->value('guest_pii_purged_at'))->toBeNull();
    (new PurgeGuestWalkInPii)->handle($this->clock);
    expect(DB::table('walkin_queue_entries')->where('id', $guest)->value('guest_pii_purged_at'))->toBe($purgedAt);
});

it('runs the purge every day from the scheduler', function () {
    $events = collect(Schedule::events())->filter(fn ($e) => str_contains((string) $e->description, PurgeGuestWalkInPii::class) || str_contains($e->getSummaryForDisplay(), 'PurgeGuestWalkInPii'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('30 3 * * *');
});
