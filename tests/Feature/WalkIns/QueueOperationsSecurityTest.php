<?php

use App\Modules\Rbac\Enums\Role;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;

/*
 * Increment 5.6-2: who may operate a queue (plan D13). The location's staff and the queue's own doctor act; nobody else does. A caller with
 * no standing at the queue gets 404 (its existence is not revealed); one who may read it but not change it gets 403. Global roles never write.
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
    $this->u = wi_triaged($this->staff, $this->svc, 'Urgent Guest');
    $this->queue = wi_queue_id();
    [$this->patientUser, $this->patientProfile, $this->patientHeaders] = remote_patient();
    $this->second = wi_second_location($this->w);
    $this->other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    [, $this->otherDoctor] = verified_doctor('7654321', 'ACTIVE');
});

function wi_outsiders(): array
{
    return [
        'the patient' => [test()->patientHeaders, 404],
        'unrelated staff' => [staff_of(test()->other), 404],
        'unrelated manager' => [test()->other['manager'], 404],
        'staff of another location' => [staff_of(test()->w, Role::ClinicStaff, test()->second), 404],
        'another doctor' => [test()->otherDoctor, 404],
        'admin' => [session_for(account(Role::Admin)), 403],
        'support' => [session_for(account(Role::SupportAgent)), 403],
        'moderator' => [session_for(account(Role::Moderator)), 404],
        'finance' => [session_for(account(Role::FinanceOfficer)), 404],
    ];
}

it('refuses call-next and every entry operation to everyone without standing, and changes nothing', function () {
    $operations = [
        ["queues/{$this->queue}/call-next", []], ["{$this->u}/call", []], ["{$this->u}/call", ['override_reason_code' => 'STAFF_CONCERN']],
        ["{$this->a}/recall", []], ["{$this->a}/skip", []], ["{$this->a}/no-show", []], ["{$this->a}/return", []], ["{$this->a}/priority", ['priority_level' => 'URGENT', 'priority_reason_code' => 'OTHER']],
    ];
    foreach (wi_outsiders() as $who => [$headers, $status]) {
        foreach ($operations as [$path, $body]) {
            wi_post($path, $headers, $body)->assertStatus($status, "{$who} {$path}");
        }
    }

    expect(DB::table('walkin_queue_entries')->whereIn('id', [$this->a, $this->u])->pluck('status')->unique()->all())->toBe(['WAITING'])
        ->and(DB::table('walkin_queue_events')->whereNotIn('event_type', ['REGISTERED', 'QUEUE_OPENED'])->count())->toBe(0)->and(DB::table('consultations')->count())->toBe(0);
});

it('lets the queue\'s doctor and the location\'s staff and manager operate it', function () {
    wi_call_next($this->queue, $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.id', $this->u);
    wi_post("{$this->u}/recall", $this->staff)->assertOk();
    wi_post("{$this->u}/skip", $this->w['doctorHeaders'])->assertOk();
    wi_post("{$this->u}/return", $this->w['manager'])->assertOk();
    wi_post("{$this->a}/priority", $this->w['manager'], ['priority_level' => 'PRIORITY', 'priority_reason_code' => 'SENIOR_PWD'])->assertOk();
});

it('refuses the doctor of another queue and a patient even for their own entry', function () {
    $remote = register_walkin($this->patientHeaders, patient_body($this->svc, $this->patientProfile))->assertCreated()->json('data.id');
    wi_post("{$remote}/arrive", $this->staff)->assertOk();
    wi_post("{$this->u}/cancel", $this->staff, ['reason_code' => 'OTHER', 'patient_informed_in_person' => true])->assertOk();
    wi_post("{$this->a}/cancel", $this->staff, ['reason_code' => 'OTHER', 'patient_informed_in_person' => true])->assertOk();
    wi_call_next($this->queue, $this->staff)->assertOk();

    foreach (['recall', 'skip', 'no-show', 'return', 'call'] as $op) {
        wi_post("{$remote}/{$op}", $this->patientHeaders)->assertNotFound();
        wi_post("{$remote}/{$op}", $this->otherDoctor)->assertNotFound();
    }
    wi_post("{$remote}/priority", $this->patientHeaders, ['priority_level' => 'URGENT', 'priority_reason_code' => 'OTHER'])->assertNotFound();
    expect(DB::table('walkin_queue_entries')->where('id', $remote)->value('triage_priority'))->toBe(0)
        ->and(wi_status($remote))->toBe('CALLED');
});

it('keeps the staff-only fields out of every patient view of a called entry', function () {
    $remote = register_walkin($this->patientHeaders, patient_body($this->svc, $this->patientProfile))->assertCreated()->json('data.id');
    wi_post("{$remote}/arrive", $this->staff)->assertOk();
    DB::table('walkin_queue_entries')->whereIn('id', [$this->a, $this->u])->update(['status' => 'CANCELLED']);
    wi_call_next($this->queue, $this->staff)->assertOk();

    $mine = wi_json('/api/v1/walk-ins/queue', $this->patientHeaders)->assertOk()->json('data.0');
    expect($mine['status'])->toBe('CALLED')->and($mine)->not->toHaveKeys(['recall_count', 'called_at', 'queue_sort_at', 'name', 'priority_level']);
});
