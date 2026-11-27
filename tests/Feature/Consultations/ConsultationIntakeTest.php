<?php

use App\Modules\Consultations\Contracts\CareRelationships;
use App\Modules\Consultations\Contracts\ConsultationIntake;
use App\Modules\Consultations\DTOs\WalkInCallIntake;
use App\Modules\Consultations\Enums\ConsultationStatus;
use App\Modules\Consultations\Models\Consultation;
use Carbon\CarbonImmutable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world();
    [, $this->profile] = remote_patient();
    $this->intake = app(ConsultationIntake::class);
    $this->call = function (string $entry) {
        $row = DB::table('walkin_queue_entries as e')->join('walkin_queues as q', 'q.id', '=', 'e.walkin_queue_id')->where('e.id', $entry)
            ->first(['e.patient_profile_id', 'q.doctor_profile_id', 'q.clinic_location_id']);

        return new WalkInCallIntake($entry, $row->doctor_profile_id, $row->patient_profile_id, $row->clinic_location_id, CarbonImmutable::now());
    };
});

it('opens a SCHEDULED consultation, idempotently per entry', function () {
    $entry = wi_seed_entry($this->w, $this->profile);

    $id = $this->intake->openForWalkIn(($this->call)($entry));
    $again = $this->intake->openForWalkIn(($this->call)($entry));

    $c = Consultation::findOrFail($id);
    expect($again)->toBe($id)->and(Consultation::count())->toBe(1)
        ->and($c->status)->toBe(ConsultationStatus::Scheduled)->and($c->walkin_queue_entry_id)->toBe($entry)
        ->and($c->patient_profile_id)->toBe($this->profile)->and($c->consultation_type)->toBe('IN_PERSON');
});

it('revives a CANCELLED consultation when the entry is called again', function () {
    $entry = wi_seed_entry($this->w, $this->profile);
    $id = $this->intake->openForWalkIn(($this->call)($entry));

    $this->intake->cancelForWalkIn($entry);
    expect(Consultation::findOrFail($id)->status)->toBe(ConsultationStatus::Cancelled);

    expect($this->intake->openForWalkIn(($this->call)($entry)))->toBe($id)
        ->and(Consultation::count())->toBe(1)
        ->and(Consultation::findOrFail($id)->status)->toBe(ConsultationStatus::Scheduled);
});

it('opens a consultation with a null patient for a guest', function () {
    $entry = wi_seed_entry($this->w, null, 'WAITING', null, 'STAFF_CONSOLE');

    $id = $this->intake->openForWalkIn(($this->call)($entry));

    expect(Consultation::findOrFail($id)->patient_profile_id)->toBeNull();
});

it('rejects a call that does not match its entry (source trigger)', function () {
    $entry = wi_seed_entry($this->w, $this->profile);
    $call = ($this->call)($entry);
    $wrong = new WalkInCallIntake($entry, $call->doctorProfileId, null, $call->clinicLocationId, $call->calledAt);

    expect(fn () => DB::transaction(fn () => $this->intake->openForWalkIn($wrong)))->toThrow(QueryException::class, 'consultation does not match its source');
    expect(Consultation::count())->toBe(0);
});

it('refuses to open over a started or finished consultation (LogicException)', function () {
    foreach ([ConsultationStatus::InProgress, ConsultationStatus::Interrupted, ConsultationStatus::Completed] as $status) {
        $entry = wi_seed_entry($this->w, $this->profile);
        $id = $this->intake->openForWalkIn(($this->call)($entry));
        DB::table('consultations')->where('id', $id)->update(['status' => $status->value, 'started_at' => now(), 'ended_at' => $status === ConsultationStatus::Completed ? now() : null, 'duration_seconds' => $status === ConsultationStatus::Completed ? 60 : null]);

        expect(fn () => $this->intake->openForWalkIn(($this->call)($entry)))->toThrow(LogicException::class);
    }
});

it('cancels idempotently, and leaves a missing, cancelled or completed row alone', function () {
    $entry = wi_seed_entry($this->w, $this->profile);

    $this->intake->cancelForWalkIn($entry);
    expect(Consultation::count())->toBe(0);

    $id = $this->intake->openForWalkIn(($this->call)($entry));
    $this->intake->cancelForWalkIn($entry);
    $this->intake->cancelForWalkIn($entry);
    expect(Consultation::findOrFail($id)->status)->toBe(ConsultationStatus::Cancelled);

    DB::table('consultations')->where('id', $id)->update(['status' => 'COMPLETED', 'started_at' => now(), 'ended_at' => now(), 'duration_seconds' => 5]);
    $this->intake->cancelForWalkIn($entry);
    expect(Consultation::findOrFail($id)->status)->toBe(ConsultationStatus::Completed);

    DB::table('consultations')->where('id', $id)->update(['status' => 'IN_PROGRESS']);
    expect(fn () => $this->intake->cancelForWalkIn($entry))->toThrow(LogicException::class);
});

it('revokes the care relationship when it cancels', function () {
    $entry = wi_seed_entry($this->w, $this->profile);
    $this->intake->openForWalkIn(($this->call)($entry));
    app(CareRelationships::class)->grantForWalkIn($entry, $this->clock->now());

    $this->intake->cancelForWalkIn($entry);

    expect(DB::table('care_relationships')->where('source_id', $entry)->value('status'))->toBe('REVOKED');
});

it('leaves the care relationship ACTIVE when the consultation is already COMPLETED', function () {
    $entry = wi_seed_entry($this->w, $this->profile);
    $id = $this->intake->openForWalkIn(($this->call)($entry));
    app(CareRelationships::class)->grantForWalkIn($entry, $this->clock->now());
    DB::table('consultations')->where('id', $id)->update(['status' => 'COMPLETED', 'started_at' => now(), 'ended_at' => now(), 'duration_seconds' => 5]);

    $this->intake->cancelForWalkIn($entry);

    expect(DB::table('care_relationships')->where('source_id', $entry)->value('status'))->toBe('ACTIVE');
});
