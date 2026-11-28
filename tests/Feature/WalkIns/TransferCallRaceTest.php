<?php

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\Rbac\Enums\Role;
use App\Modules\WalkIns\Enums\CancelReason;
use App\Modules\WalkIns\Exceptions\ConcurrentRegistration;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Services\EntryActions;
use App\Modules\WalkIns\Services\QueueOperations;
use App\Shared\Contracts\Clock;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;

/*
 * Increment 5.8-2: an entry can move queue (transfer, D-G), so a change that read the entry before the move must not write to the wrong queue
 * (review M2: 409 CONCURRENT_MODIFICATION, retry). Brief §4: a returned entry with a cancelled consultation is transferred, then called and
 * started by the new doctor, on the same consultation (the id is kept).
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    consultation_clock($this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    [$this->staffUser, $this->staff] = clinic_member($this->w['clinic'], Role::ClinicStaff);
    [$this->user, $this->profile, $this->patient] = remote_patient();
    doctor_in($this->w);
    $this->remote = register_walkin($this->patient, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    wi_post("{$this->remote}/arrive", $this->staff)->assertOk();
    $this->source = wi_queue_id();

    // A second doctor, checked in, with a queue for today (a guest creates it); the guest leaves so the transferred entry is next there.
    $this->second = wi_second_doctor($this->w);
    checkin($this->second['headers'], $this->w['location'])->assertSuccessful();
    $guest = register_walkin($this->staff, guest_body($this->second['service'], 'Waiting Guest'))->assertCreated()->json('data.id');
    wi_post("{$guest}/cancel", $this->staff, ['reason_code' => 'OTHER', 'patient_informed_in_person' => true])->assertOk();
    $this->target = (string) DB::table('walkin_queues')->where('doctor_profile_id', $this->second['doctor']->id)->value('id');
});

it('a cancel that read the entry before it moved gets 409 CONCURRENT_MODIFICATION and writes nothing', function () {
    $stale = WalkInQueueEntry::query()->whereKey($this->remote)->firstOrFail();
    wi_post("{$this->remote}/transfer", $this->staff, ['walkin_queue_id' => $this->target])->assertOk();
    $events = DB::table('walkin_queue_events')->count();

    expect(fn () => app(EntryActions::class)->cancel($this->staffUser, $stale, CancelReason::PatientRequest, true))
        ->toThrow(ConcurrentRegistration::class);

    expect(wi_status($this->remote))->toBe('WAITING')
        ->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $this->remote)->where('event_type', 'CANCELLED')->count())->toBe(0)
        ->and(DB::table('walkin_queue_events')->count())->toBe($events);
});

it('a call by id that read the entry before it moved gets 409 CONCURRENT_MODIFICATION and calls nobody', function () {
    $stale = WalkInQueueEntry::query()->whereKey($this->remote)->firstOrFail();
    wi_post("{$this->remote}/transfer", $this->staff, ['walkin_queue_id' => $this->target])->assertOk();

    expect(fn () => app(QueueOperations::class)->call($this->staffUser, $stale, null))->toThrow(ConcurrentRegistration::class);

    expect(wi_status($this->remote))->toBe('WAITING')->and(DB::table('consultations')->count())->toBe(0);
});

it('a returned entry with a cancelled consultation is transferred, then called and started by the new doctor, on the same consultation', function () {
    wi_call_next($this->source, $this->staff)->assertOk()->assertJsonPath('data.id', $this->remote);
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $this->remote)->value('id');
    wi_post("{$this->remote}/skip", $this->staff)->assertOk();
    wi_post("{$this->remote}/return", $this->staff)->assertOk();
    expect(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('CANCELLED');

    wi_post("{$this->remote}/transfer", $this->staff, ['walkin_queue_id' => $this->target])->assertOk()->assertJsonPath('data.id', $this->remote);

    wi_post("{$this->remote}/call", $this->second['headers'])->assertOk()->assertJsonPath('data.status', 'CALLED');
    act_post("/api/v1/consultations/{$consultation}/start", $this->second['headers'])->assertOk();

    $doctor = $this->second['doctor']->id;
    expect(DB::table('consultations')->where('id', $consultation)->value('doctor_profile_id'))->toBe($doctor)
        ->and(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('IN_PROGRESS')
        ->and(DB::table('care_relationships')->where('source_id', $this->remote)->where('status', 'ACTIVE')->pluck('doctor_profile_id')->all())->toBe([$doctor]);
});
