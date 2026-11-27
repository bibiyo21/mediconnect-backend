<?php

use App\Modules\WalkIns\Jobs\EmitEntryHints;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use App\Modules\WalkIns\Services\PatientAccess;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Increment 5.6-3a: the entry-hint fan-out (decisions D16 and D17). Every live entry of a queue is hinted when its position or estimate
 * may have moved, on the account that may see it NOW. Debounced: a leading round now and at most one trailing round per window.
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
    config(['queue.default' => 'redis']); // the trailing round is only scheduled off the sync queue
    Cache::flush();
    Queue::fake(); // forget the set-up traffic
});

/** @return list<array{name: string, channels: list<string>, payload: array<string, mixed>}> */
function fan_emitted(string $name = 'WalkInEntryUpdated'): array
{
    return Queue::pushed(BroadcastEvent::class)->map(fn ($job) => [
        'name' => $job->event->broadcastAs(),
        'channels' => array_map(fn ($c) => $c->name, $job->event->broadcastOn()),
        'payload' => $job->event->broadcastWith(),
    ])->filter(fn ($e) => $e['name'] === $name)->values()->all();
}

it('tells the other live patients on their own channel when a call moves their place', function () {
    // The guest is registered first and arrives first, so the guest is the head and is called. The patient is the bystander.
    $guest = wi_guest($this->staff, $this->svc, 'Head Guest');
    [$user, $profile, $headers] = remote_patient();
    register_walkin($headers, patient_body($this->svc, $profile))->assertCreated();
    $bystander = DB::table('walkin_queue_entries')->where('patient_profile_id', $profile)->value('id');
    wi_post("{$bystander}/arrive", $this->staff)->assertOk(); // staff-registered guests are WAITING already

    Cache::flush(); // a fresh window, so the call's round is the leading one
    Queue::fake();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();

    $toBystander = array_values(array_filter(fan_emitted(), fn ($e) => $e['payload']['entry_id'] === $bystander));
    expect($toBystander)->not->toBeEmpty();
    foreach ($toBystander as $hint) {
        expect($hint['channels'])->toBe(["presence-user.{$user->id}"])
            ->and(array_keys($hint['payload']))->toEqual(['entry_id', 'status', 'queue_version', 'occurred_at']);
    }
});

it('sends one leading round and at most one trailing round for a burst of changes in one window', function () {
    [$user, $profile, $headers] = remote_patient();
    register_walkin($headers, patient_body($this->svc, $profile))->assertCreated();
    $bystander = DB::table('walkin_queue_entries')->where('patient_profile_id', $profile)->value('id');
    $guests = [];
    for ($i = 1; $i <= 5; $i++) {
        $guests[] = wi_guest($this->staff, $this->svc, "Burst Guest {$i}");
    }
    Cache::flush();
    Queue::fake();

    // Five real changes (a priority change each) inside one window.
    foreach ($guests as $guest) {
        wi_post("{$guest}/priority", $this->staff, ['priority_level' => 'PRIORITY', 'priority_reason_code' => 'OTHER'])->assertOk();
    }

    $leading = array_values(array_filter(fan_emitted(), fn ($e) => $e['payload']['entry_id'] === $bystander));
    expect($leading)->toHaveCount(1); // one leading round, sent at once
    Queue::assertPushed(EmitEntryHints::class, 1); // the other four changes schedule exactly one trailing round

    // Running the trailing round sends the second and last round for this window.
    (new EmitEntryHints(wi_queue_id()))->handle(app(QueueRealtime::class));
    $all = array_values(array_filter(fan_emitted(), fn ($e) => $e['payload']['entry_id'] === $bystander));
    expect($all)->toHaveCount(2)->and($all[1]['channels'])->toBe(["presence-user.{$user->id}"]);
});

it('does not fan out to guests: a guest has no account and gets no entry hint', function () {
    $guest = wi_guest($this->staff, $this->svc, 'Only Guest');
    Cache::flush();
    Queue::fake();
    wi_post("{$guest}/priority", $this->staff, ['priority_level' => 'PRIORITY', 'priority_reason_code' => 'OTHER'])->assertOk();

    expect(fan_emitted())->toBeEmpty();
});

it('resolves the audience of many profiles in two queries, whatever the count', function () {
    $access = app(PatientAccess::class);
    [$user, $profile] = remote_patient();

    $resolve = function (array $ids) use ($access): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $access->userIdsFor($ids);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$result, $count];
    };

    [$one, $oneQueries] = $resolve([$profile]);
    expect($one)->toBe([$profile => [$user->id]])->and($oneQueries)->toBe(2);

    $many = [$profile, ...array_map(fn () => (string) Str::uuid7(), range(1, 19))];
    [$twenty, $manyQueries] = $resolve($many);
    expect($twenty[$profile])->toBe([$user->id])->and(count($twenty))->toBe(20)->and($manyQueries)->toBe(2);
});

it('keeps a priority change that changes nothing silent: no event, no audit row, no realtime, no version bump', function () {
    $entry = wi_guest($this->staff, $this->svc, 'Priority Guest');
    wi_post("{$entry}/priority", $this->staff, ['priority_level' => 'PRIORITY', 'priority_reason_code' => 'OTHER', 'priority_note' => 'Same note'])->assertOk();
    $events = DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'PRIORITY_CHANGED')->count();
    $audits = DB::table('audit_logs')->where('action', 'walkins.priority_changed')->count();
    // A fresh window, so a regression that still called entryChanged would reach the fan-out and be caught by the nothing-pushed check.
    Cache::flush();
    Queue::fake();

    wi_post("{$entry}/priority", $this->staff, ['priority_level' => 'PRIORITY', 'priority_reason_code' => 'OTHER', 'priority_note' => 'Same note'])->assertOk();

    expect(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'PRIORITY_CHANGED')->count())->toBe($events)
        ->and(DB::table('audit_logs')->where('action', 'walkins.priority_changed')->count())->toBe($audits);
    Queue::assertNothingPushed();
});

it('writes a priority event for a note-only change, with codes only', function () {
    $entry = wi_guest($this->staff, $this->svc, 'Priority Guest');
    wi_post("{$entry}/priority", $this->staff, ['priority_level' => 'PRIORITY', 'priority_reason_code' => 'OTHER', 'priority_note' => 'First note'])->assertOk();
    $before = DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'PRIORITY_CHANGED')->count();

    wi_post("{$entry}/priority", $this->staff, ['priority_level' => 'PRIORITY', 'priority_reason_code' => 'OTHER', 'priority_note' => 'Changed note'])->assertOk();

    $after = DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'PRIORITY_CHANGED')->get();
    expect($after)->toHaveCount($before + 1)
        ->and($after->last()->metadata)->not->toContain('Changed note')->not->toContain('First note');
});

it('never grants a care relationship on the call, recall, skip, return, no-show or cancel paths of the queue', function () {
    // REMOTE patient entries only: a guest has no patient profile, so grantForWalkIn would return null and prove nothing.
    // Order matters: each entry is called only when it is next in line (call-next takes the head).
    // 1. A patient called, skipped and marked no-show.
    [, $noShowProfile, $noShowHeaders] = remote_patient();
    register_walkin($noShowHeaders, patient_body($this->svc, $noShowProfile))->assertCreated();
    $noShow = DB::table('walkin_queue_entries')->where('patient_profile_id', $noShowProfile)->value('id');
    wi_post("{$noShow}/arrive", $this->staff)->assertOk();
    wi_post("{$noShow}/call", $this->staff)->assertOk(); // the direct call endpoint, not call-next
    wi_post("{$noShow}/skip", $this->staff)->assertOk();
    wi_post("{$noShow}/no-show", $this->staff)->assertOk();

    // 2. A patient called, skipped and then cancelled by staff.
    [, $cancelProfile, $cancelHeaders] = remote_patient();
    register_walkin($cancelHeaders, patient_body($this->svc, $cancelProfile))->assertCreated();
    $cancelled = DB::table('walkin_queue_entries')->where('patient_profile_id', $cancelProfile)->value('id');
    wi_post("{$cancelled}/arrive", $this->staff)->assertOk();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    wi_post("{$cancelled}/skip", $this->staff)->assertOk();
    wi_post("{$cancelled}/cancel", $this->staff, ['reason_code' => 'PATIENT_REQUEST'])->assertOk();

    // 3. A patient called, recalled, skipped and returned to the queue.
    [, $profile, $headers] = remote_patient();
    register_walkin($headers, patient_body($this->svc, $profile))->assertCreated();
    $entry = DB::table('walkin_queue_entries')->where('patient_profile_id', $profile)->value('id');
    wi_post("{$entry}/arrive", $this->staff)->assertOk();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();
    wi_post("{$entry}/recall", $this->staff)->assertOk();
    wi_post("{$entry}/skip", $this->staff)->assertOk();
    wi_post("{$entry}/return", $this->staff)->assertOk();
    // 4. The returned entry is called again, directly (a second call after the return).
    wi_post("{$entry}/call", $this->staff)->assertOk();

    expect(DB::table('walkin_queue_entries')->where('id', $noShow)->value('status'))->toBe('NO_SHOW')
        ->and(DB::table('walkin_queue_entries')->where('id', $cancelled)->value('status'))->toBe('CANCELLED')
        ->and(DB::table('walkin_queue_entries')->where('id', $entry)->value('status'))->toBe('CALLED')
        ->and(DB::table('care_relationships')->whereIn('source_id', [$noShow, $cancelled, $entry])->count())->toBe(0)
        ->and(DB::table('care_relationships')->count())->toBe(0);
});

it('resolves the guardian audience of many profiles exactly: authorized guardians in, revoked ones out', function () {
    [$guardian, $guardianProfile, $headers] = remote_patient();
    $authorised = wi_dependent($headers);
    $revoked = wi_dependent($headers);
    app('auth')->forgetGuards();
    $this->deleteJson("/api/v1/patients/me/dependents/{$revoked}", [], $headers)->assertSuccessful();
    $guardianUser = (string) $guardian->id;

    $map = app(PatientAccess::class)->userIdsFor([$guardianProfile, $authorised, $revoked]);

    expect($map)->toBe([
        $guardianProfile => [$guardianUser],
        $authorised => [$guardianUser],
        $revoked => [],
    ]);
});

it('fans out to the other live entries for a cancel, a pause and a reopen, and never to a finished entry', function () {
    // The head of the line is marked no-show first (call-next takes the head; no-show needs SKIPPED after a call).
    // Both finished entries are REMOTE patient entries: a guest has no patient profile, so the entry hint would never be sent to it anyway.
    [, $noShowProfile, $noShowHeaders] = remote_patient();
    register_walkin($noShowHeaders, patient_body($this->svc, $noShowProfile))->assertCreated();
    $noShow = DB::table('walkin_queue_entries')->where('patient_profile_id', $noShowProfile)->value('id');
    wi_post("{$noShow}/arrive", $this->staff)->assertOk();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk(); // the head of the line
    wi_post("{$noShow}/skip", $this->staff)->assertOk();
    wi_post("{$noShow}/no-show", $this->staff)->assertOk();

    [$user, $profile, $headers] = remote_patient();
    register_walkin($headers, patient_body($this->svc, $profile))->assertCreated();
    $bystander = DB::table('walkin_queue_entries')->where('patient_profile_id', $profile)->value('id');
    wi_post("{$bystander}/arrive", $this->staff)->assertOk();
    $queue = wi_queue_id();

    [, $goneProfile, $goneHeaders] = remote_patient();
    register_walkin($goneHeaders, patient_body($this->svc, $goneProfile))->assertCreated();
    $gone = DB::table('walkin_queue_entries')->where('patient_profile_id', $goneProfile)->value('id');
    wi_post("{$gone}/arrive", $this->staff)->assertOk();

    Cache::flush();
    Queue::fake();
    wi_post("{$gone}/cancel", $this->staff, ['reason_code' => 'PATIENT_LEFT', 'patient_informed_in_person' => true])->assertOk();
    $afterCancel = array_values(array_filter(fan_emitted(), fn ($e) => $e['payload']['entry_id'] === $bystander));
    expect($afterCancel)->not->toBeEmpty();
    foreach ($afterCancel as $hint) {
        expect($hint['channels'])->toBe(["presence-user.{$user->id}"]);
    }

    // The pause and the reopen rounds must never hint a finished entry. The cancel's own immediate hint to its owner is legitimate, so it is not checked here.
    $finished = [$gone, $noShow];

    Cache::flush();
    Queue::fake();
    wi_post("queues/{$queue}/pause", $this->staff)->assertOk();
    expect(array_values(array_filter(fan_emitted(), fn ($e) => $e['payload']['entry_id'] === $bystander)))->not->toBeEmpty()
        ->and(array_filter(fan_emitted(), fn ($e) => in_array($e['payload']['entry_id'], $finished, true)))->toBeEmpty();

    Cache::flush();
    Queue::fake();
    wi_post("queues/{$queue}/open", $this->staff)->assertOk();
    expect(array_values(array_filter(fan_emitted(), fn ($e) => $e['payload']['entry_id'] === $bystander)))->not->toBeEmpty()
        ->and(array_filter(fan_emitted(), fn ($e) => in_array($e['payload']['entry_id'], $finished, true)))->toBeEmpty();
});

it('sends exactly one hint per entry on the leading edge, the changed entry included (no duplicate own-entry hint)', function () {
    // The head is a patient, so the changed entry has an account to hint: the own-entry skip is really exercised.
    [, $headProfile, $headHeaders] = remote_patient();
    register_walkin($headHeaders, patient_body($this->svc, $headProfile))->assertCreated();
    $head = DB::table('walkin_queue_entries')->where('patient_profile_id', $headProfile)->value('id');
    wi_post("{$head}/arrive", $this->staff)->assertOk(); // WAITING, first in order
    [, $profile, $headers] = remote_patient();
    register_walkin($headers, patient_body($this->svc, $profile))->assertCreated();
    $bystander = DB::table('walkin_queue_entries')->where('patient_profile_id', $profile)->value('id');
    wi_post("{$bystander}/arrive", $this->staff)->assertOk();

    // A call of the head changes its status (entryChanged). The leading round must hint each live entry once, the called one included.
    Cache::flush();
    Queue::fake();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk()->assertJsonPath('data.id', $head);

    $perEntry = collect(fan_emitted())->countBy(fn ($e) => $e['payload']['entry_id']);
    expect($perEntry[$head])->toBe(1)
        ->and($perEntry[$bystander])->toBe(1)
        ->and($perEntry->every(fn ($count) => $count === 1))->toBeTrue();
});

it('hints the changed patient entry once on the leading edge (own entry, not repeated by the fan-out)', function () {
    [$user, $profile, $headers] = remote_patient();
    register_walkin($headers, patient_body($this->svc, $profile))->assertCreated();
    $own = DB::table('walkin_queue_entries')->where('patient_profile_id', $profile)->value('id');
    Cache::flush();
    Queue::fake();

    wi_post("{$own}/arrive", $this->staff)->assertOk(); // WAITING: the status change of its own entry

    $toOwn = array_values(array_filter(fan_emitted(), fn ($e) => $e['payload']['entry_id'] === $own));
    expect($toOwn)->toHaveCount(1)->and($toOwn[0]['channels'])->toBe(["presence-user.{$user->id}"]);
});
