<?php

use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/*
 * Increment 5.5a: reads. The staff board (names for the location's staff, restricted view for global roles, minutes for the next
 * waiting entries only), one entry (the guest's phone appears only in the staff detail, audited), the staff dedupe search,
 * the patient's own entries, and the join preview.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    [$this->user, $this->profile, $this->headers] = remote_patient();
    doctor_in($this->w);
    $this->juan = register_walkin($this->staff, guest_body($this->svc, 'Juan Dela Cruz', ['priority_level' => 'NORMAL']))->assertCreated()->json('data.id');
    $this->maria = register_walkin($this->staff, guest_body($this->svc, 'Maria Clara', ['guest' => ['name' => 'Maria Clara', 'phone' => '+63 918 555 0100'], 'priority_level' => 'URGENT', 'priority_reason_code' => 'PREGNANT', 'priority_note' => 'near term']))->assertCreated()->json('data.id');
    $this->ana = register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    $this->board = "/api/v1/walk-ins/queue?clinic_location_id={$this->w['location']}";
});

it('shows the staff board in call order: urgent first, then arrival, then patients not yet arrived', function () {
    $r = wi_json($this->board, $this->staff)->assertOk();

    $queue = $r->json('data.queues.0');
    expect($queue['number_prefix'])->toBe('A')->and($queue['capacity'])->toBe(7)->and($queue['live_count'])->toBe(3)->and($queue['doctor_name'])->toBe('Dr. Ana Santos')
        ->and(collect($queue['entries'])->pluck('label')->all())->toBe(['A-02', 'A-01', 'A-03'])
        ->and(collect($queue['entries'])->pluck('name')->all())->toBe(['Maria Clara', 'Juan Dela Cruz', 'Ana Santos'])
        ->and(collect($queue['entries'])->pluck('estimate.position')->all())->toBe([1, 2, 3])
        ->and($r->json('data.restricted'))->toBeFalse()->and($r->json('data.timezone'))->toBe('Asia/Manila');
    // The phone and the note appear only in the detail of one entry.
    expect($r->getContent())->not->toContain('555')->not->toContain('near term')->not->toContain('guest_phone');
    expect($queue['entries'][1]['estimate']['basis'])->toContain('queue_ahead')->and($queue['entries'][0]['estimate']['basis'])->not->toContain('queue_ahead');
});

it('shows minutes for the next waiting entries only (walk-in-management §9.5)', function () {
    config(['walkins.board_minutes_for_next' => 1]);

    $entries = wi_json($this->board, $this->staff)->assertOk()->json('data.queues.0.entries');

    expect($entries[0]['estimate']['min_minutes'])->not->toBeNull()
        ->and($entries[1]['estimate']['min_minutes'])->toBeNull()->and($entries[1]['estimate']['position'])->toBe(2)->and($entries[1]['estimate']['basis'])->not->toBeEmpty()
        ->and($entries[2]['estimate']['min_minutes'])->toBeNull();
});

it('limits global-scope readers to a restricted view, and audits every such read', function () {
    foreach ([Role::Admin, Role::SupportAgent] as $role) {
        $r = wi_json($this->board, session_for(account($role)))->assertOk()->assertJsonPath('data.restricted', true);
        foreach ($r->json('data.queues.0.entries') as $entry) {
            expect($entry)->not->toHaveKeys(['name', 'hmo_claim', 'guest_phone', 'priority_note', 'estimate', 'priority_reason_code']);
        }
        expect($r->getContent())->not->toContain('Juan')->not->toContain('Maria')->not->toContain('Clara')->not->toContain('Cruz');
    }
    $audit = DB::table('audit_logs')->where('action', 'walkins.board_read')->get();
    expect($audit)->toHaveCount(2)->and($audit->every(fn ($a) => (bool) $a->is_access_event))->toBeTrue()->and(json_decode($audit[0]->changes, true))->toEqual(['queues' => 1, 'entries' => 3, 'audience' => 'RESTRICTED']);
});

it('lets the queue\'s doctor see names for their own queue only', function () {
    $r = wi_json($this->board, $this->w['doctorHeaders'])->assertOk();
    expect($r->json('data.queues.0.entries.0.name'))->toBe('Maria Clara')->and($r->json('data.restricted'))->toBeFalse()->and($r->getContent())->not->toContain('guest_phone');

    // Another doctor of this clinic location sees no one else's queue.
    $other = directory_doctor('ACTIVE', 'Dr. Other', ClinicLocation::findOrFail($this->w['location']));
    wi_json($this->board, session_for(User::findOrFail($other->user_id)))->assertForbidden(); // no Doctor role in this fixture: refused
});

it('refuses the board to everyone without a reason to see it', function () {
    $second = wi_second_location($this->w);
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    [, $outsideDoctor] = verified_doctor('7654321', 'ACTIVE');

    foreach ([
        'unrelated staff' => staff_of($other), 'unrelated manager' => $other['manager'], 'staff of another location' => staff_of($this->w, Role::ClinicStaff, $second),
        'finance' => session_for(account(Role::FinanceOfficer)), 'moderator' => session_for(account(Role::Moderator)), 'patient' => $this->headers, 'doctor of another clinic' => $outsideDoctor,
    ] as $who => $headers) {
        wi_json($this->board, $headers)->assertForbidden();
    }
    app('auth')->forgetGuards();
    $this->getJson($this->board)->assertUnauthorized();
    wi_json("/api/v1/walk-ins/queue?clinic_location_id={$this->w['location']}&date=nope", $this->staff)->assertStatus(422);
});

it('filters the board by doctor and date', function () {
    wi_json($this->board.'&doctor_id='.$this->w['doctor']->id, $this->staff)->assertOk()->assertJsonCount(1, 'data.queues');
    wi_json($this->board.'&doctor_id='.Str::uuid7(), $this->staff)->assertOk()->assertJsonCount(0, 'data.queues');
    wi_json($this->board.'&date='.$this->clock->now()->subDay()->setTimezone('Asia/Manila')->toDateString(), $this->staff)->assertOk()->assertJsonCount(0, 'data.queues');
});

it('leaves cancelled and completed entries off the board', function () {
    DB::table('walkin_queue_entries')->where('id', $this->juan)->update(['status' => 'CANCELLED']);
    DB::table('walkin_queue_entries')->where('id', $this->maria)->update(['status' => 'COMPLETED', 'completed_at' => now()]);

    expect(wi_json($this->board, $this->staff)->json('data.queues.0.entries.*.label'))->toBe(['A-03']);
});

it('shows the staff detail with the guest\'s phone, and audits that read without the values', function () {
    $r = wi_json("/api/v1/walk-ins/{$this->maria}", $this->staff)->assertOk();

    expect($r->json('data.guest_phone'))->toBe('+639185550100')->and($r->json('data.name'))->toBe('Maria Clara')->and($r->json('data.priority_note'))->toBe('near term')
        ->and($r->json('data.priority_reason_code'))->toBe('PREGNANT')->and($r->json('data.estimate.basis'))->not->toBeEmpty();
    $audit = DB::table('audit_logs')->where('action', 'walkins.entry_read')->sole();
    expect((bool) $audit->is_access_event)->toBeTrue()->and($audit->subject_id)->toBe($this->maria)->and(json_decode($audit->changes, true))->toEqual(['audience' => 'STAFF', 'exposes_guest_data' => true])
        ->and($audit->changes)->not->toContain('Maria')->not->toContain('0100');
});

it('does not audit the detail of a patient\'s entry, and shows the doctor names but no phone', function () {
    wi_json("/api/v1/walk-ins/{$this->ana}", $this->staff)->assertOk()->assertJsonPath('data.name', 'Ana Santos')->assertJsonMissingPath('data.guest_phone');
    expect(DB::table('audit_logs')->where('action', 'walkins.entry_read')->count())->toBe(0);

    $r = wi_json("/api/v1/walk-ins/{$this->maria}", $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.name', 'Maria Clara');
    expect($r->json('data'))->not->toHaveKeys(['guest_phone', 'priority_note']);
    expect(json_decode(DB::table('audit_logs')->where('action', 'walkins.entry_read')->sole()->changes, true)['audience'])->toBe('DOCTOR');
});

it('gives a global-scope reader the restricted detail and audits it', function () {
    $r = wi_json("/api/v1/walk-ins/{$this->maria}", session_for(account(Role::SupportAgent)))->assertOk();

    expect($r->json('data'))->not->toHaveKeys(['name', 'guest_phone', 'priority_note', 'hmo_claim', 'estimate'])->and($r->getContent())->not->toContain('Maria');
    expect(json_decode(DB::table('audit_logs')->where('action', 'walkins.entry_read')->sole()->changes, true)['audience'])->toBe('RESTRICTED');
});

it('shows the patient only their own entry, with the patient estimate view and no staff data', function () {
    $r = wi_json("/api/v1/walk-ins/{$this->ana}", $this->headers)->assertOk();

    expect($r->json('data.label'))->toBe('A-03')->and($r->json('data.estimate'))->toHaveKeys(['position', 'min_minutes', 'max_minutes', 'is_estimate', 'may_not_be_served_today', 'wait_status'])
        ->and($r->json('data.estimate'))->not->toHaveKey('basis')->and($r->json('data'))->not->toHaveKeys(['name', 'guest_phone', 'priority_level', 'registration_channel', 'hmo_claim']);

    [, , $stranger] = remote_patient();
    foreach ([$this->ana, $this->maria] as $id) {
        wi_json("/api/v1/walk-ins/{$id}", $stranger)->assertNotFound();
    }
    wi_json("/api/v1/walk-ins/{$this->maria}", $this->headers)->assertNotFound();
});

it('shows a guardian the entry of their dependant and nobody else', function () {
    $dependant = wi_dependent($this->headers);
    $id = register_walkin($this->headers, patient_body($this->svc, $dependant))->assertCreated()->json('data.id');
    [, , $stranger] = remote_patient();

    wi_json("/api/v1/walk-ins/{$id}", $this->headers)->assertOk();
    wi_json("/api/v1/walk-ins/{$id}", $stranger)->assertNotFound();
});

it('answers 404 to unrelated staff and other roles (the entry\'s existence is not revealed), 401 to nobody', function () {
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);

    foreach (['unrelated staff' => staff_of($other), 'finance' => session_for(account(Role::FinanceOfficer)), 'moderator' => session_for(account(Role::Moderator))] as $headers) {
        wi_json("/api/v1/walk-ins/{$this->maria}", $headers)->assertNotFound();
    }
    wi_json('/api/v1/walk-ins/'.Str::uuid7(), $this->staff)->assertNotFound();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/walk-ins/{$this->maria}")->assertUnauthorized();
    expect(DB::table('audit_logs')->where('action', 'walkins.entry_read')->count())->toBe(0);
});

it('lists only the patient\'s own entries without a location', function () {
    $dependant = wi_dependent($this->headers);
    register_walkin($this->headers, patient_body($this->svc, $dependant))->assertCreated();
    [, $profile2, $headers2] = remote_patient();
    register_walkin($headers2, patient_body($this->svc, $profile2))->assertCreated();

    $mine = wi_json('/api/v1/walk-ins/queue', $this->headers)->assertOk()->json('data');
    $other = wi_json('/api/v1/walk-ins/queue', $headers2)->assertOk()->json('data');
    expect(collect($mine)->pluck('label')->sort()->values()->all())->toBe(['A-03', 'A-04'])->and(collect($other)->pluck('label')->all())->toBe(['A-05'])
        ->and(json_encode($mine))->not->toContain('Maria')->not->toContain('guest_phone');
    expect(wi_json('/api/v1/walk-ins/queue?date='.$this->clock->now()->addDay()->setTimezone('Asia/Manila')->toDateString(), $this->headers)->json('data'))->toBe([]);
});

// Search (A3, A4).

it('searches the location\'s live entries by number, phone and name, and never returns a phone', function () {
    $search = fn (string $term, ?array $headers = null) => wi_search($this->w['location'], $term, $headers ?? $this->staff);

    foreach (['A-01', 'a-01', '1', '01'] as $term) {
        expect($search($term)->assertOk()->json('data.*.id'))->toBe([$this->juan])->and($search($term)->json('meta.match_type'))->toBe('number');
    }
    foreach (['0917 123 4567', '+639171234567', '9171234567', '0917-123-4567'] as $term) {
        $r = $search($term)->assertOk();
        expect($r->json('data.*.id'))->toBe([$this->juan])->and($r->json('meta.match_type'))->toBe('phone')->and($r->getContent())->not->toContain('9171234567')->not->toContain('+63917');
    }
    expect($search('maria')->json('data.0.name'))->toBe('Maria Clara')->and($search('CRUZ')->json('data.0.id'))->toBe($this->juan)->and($search('ana santos')->json('data.0.id'))->toBe($this->ana)
        ->and($search('maria')->json('meta.match_type'))->toBe('name')->and($search('nobody here')->json('data'))->toBe([]);
    expect($search('Maria')->json('data.0'))->not->toHaveKeys(['guest_phone', 'priority_note']);

    $audit = DB::table('audit_logs')->where('action', 'walkins.search')->get();
    expect($audit)->not->toBeEmpty()->and($audit->every(fn ($a) => (bool) $a->is_access_event))->toBeTrue()
        ->and(json_encode($audit))->not->toContain('maria')->not->toContain('9171234567')->not->toContain('Cruz')
        ->and($audit->contains(fn ($a) => json_decode($a->changes, true) == ['type' => 'number', 'count' => 1]))->toBeTrue();
});

it('leaves cancelled entries and other locations out of the search, caps the name scan, and rejects unreadable input', function () {
    DB::table('walkin_queue_entries')->where('id', $this->juan)->update(['status' => 'CANCELLED']);
    $url = fn (string $term, ?string $location = null) => [$location ?? $this->w['location'], $term];

    expect(wi_search(...$url('juan'), headers: $this->staff)->json('data'))->toBe([]);
    $second = wi_second_location($this->w);
    expect(wi_search(...$url('maria', $second), headers: $this->w['manager'])->assertOk()->json('data'))->toBe([]);

    config(['walkins.search_max_rows' => 1]); // only the first row of the queue is read
    expect(wi_search(...$url('ana santos'), headers: $this->staff)->json('data'))->toBe([]);

    wi_search(...$url('0917 123 45'), headers: $this->staff)->assertStatus(422)->assertJsonValidationErrorFor('term', 'errors');
    wi_search(...$url('a'), headers: $this->staff)->assertStatus(422);
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/walk-ins/search', ['term' => 'maria'], $this->staff)->assertStatus(422);
});

it('lets only the location\'s staff search', function () {
    $second = wi_second_location($this->w);
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    $go = fn (array $headers) => wi_search($this->w['location'], 'maria', $headers);

    foreach ([
        'patient' => $this->headers, 'admin' => session_for(account(Role::Admin)), 'support' => session_for(account(Role::SupportAgent)), 'finance' => session_for(account(Role::FinanceOfficer)),
        'unrelated staff' => staff_of($other), 'staff of another location' => staff_of($this->w, Role::ClinicStaff, $second), 'the doctor (A2 not granted)' => $this->w['doctorHeaders'],
    ] as $who => $headers) {
        $go($headers)->assertForbidden();
    }
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/walk-ins/search', ['clinic_location_id' => $this->w['location'], 'term' => 'maria'])->assertUnauthorized();

    // The doctor, once DOCTOR holds walkins.register, searches their own queue.
    RoleModel::findByName('DOCTOR', 'web')->givePermissionTo('walkins.register');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($go($this->w['doctorHeaders'])->assertOk()->json('data.0.name'))->toBe('Maria Clara');
    expect($go(staff_of($this->w, Role::ClinicStaff, $this->w['location']))->assertOk()->json('data'))->toHaveCount(1);
});

it('throttles the search per user', function () {
    config(['walkins.limits.search_per_minute' => 2]);
    $go = fn () => wi_search($this->w['location'], 'maria', $this->staff);

    $go()->assertOk();
    $go()->assertOk();
    $go()->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
});

// Join preview (E8).

it('previews the queue for a patient: state, whether the app can register them, the fee and a patient-safe estimate, with no counts', function () {
    $url = "/api/v1/walk-ins/join-preview?doctor_service_id={$this->svc}";
    $r = wi_json($url, $this->headers)->assertOk();

    expect($r->json('data'))->toHaveKeys(['state', 'state_reason_code', 'accepts_remote_registration', 'remote_refusal_reason_code', 'fee', 'estimate', 'requires_late_risk_acknowledgement', 'doctor_name', 'location_name', 'timezone', 'local_date'])
        ->and($r->json('data.state'))->toBe('OPEN')->and($r->json('data.accepts_remote_registration'))->toBeTrue()->and($r->json('data.fee.amount'))->toBe(65000)
        ->and($r->json('data.estimate'))->toHaveKeys(['min_minutes', 'max_minutes', 'is_estimate', 'wait_status'])->and($r->json('data.estimate'))->not->toHaveKeys(['basis', 'position'])
        ->and($r->json('data.requires_late_risk_acknowledgement'))->toBeFalse()
        ->and(strtolower($r->getContent()))->not->toContain('capacity')->not->toContain('remaining')->not->toContain('queue_length')->not->toContain('count');
});

it('previews each state the Join button depends on (E8)', function () {
    $url = "/api/v1/walk-ins/join-preview?doctor_service_id={$this->svc}";
    $data = fn (?array $headers = null) => wi_json($url, $headers ?? $this->headers)->assertOk()->json('data');
    $row = DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id);

    presence_post('unavailable', $this->w['doctorHeaders'], ['reason' => 'PHONE_CALL'])->assertOk();
    expect($data())->toMatchArray(['state' => 'PAUSED', 'state_reason_code' => 'doctor_unavailable', 'accepts_remote_registration' => false, 'remote_refusal_reason_code' => 'doctor_unavailable', 'estimate' => null]);
    presence_post('available', $this->w['doctorHeaders'])->assertOk();

    wi_post('queues/'.DB::table('walkin_queues')->value('id').'/pause', $this->staff)->assertOk();
    expect($data())->toMatchArray(['state' => 'PAUSED', 'state_reason_code' => 'paused', 'accepts_remote_registration' => false, 'remote_refusal_reason_code' => 'paused', 'estimate' => null]);
    wi_post('queues/'.DB::table('walkin_queues')->value('id').'/open', $this->staff)->assertOk();

    $row->update(['current_status' => 'OUT', 'checked_out_at' => $this->clock->now()->addMinute(), 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_method' => 'SYSTEM']);
    expect($data())->toMatchArray(['state' => 'CLOSED', 'state_reason_code' => 'doctor_checked_out', 'accepts_remote_registration' => false, 'remote_refusal_reason_code' => 'doctor_checked_out']);
    $row->update(['current_status' => 'IN', 'checked_out_at' => null, 'checkout_reason' => null, 'checked_out_by' => null, 'checked_out_method' => null]);

    $this->clock->at = next_monday_at('11:45');
    expect($data())->toMatchArray(['state' => 'CLOSED', 'state_reason_code' => 'cutoff_passed', 'accepts_remote_registration' => false]);
    $this->clock->at = next_monday_at('10:00')->addDay();
    expect($data())->toMatchArray(['state' => 'CLOSED', 'state_reason_code' => 'no_walkin_hours_today']);
});

it('previews "not started" before check-in, and the reasons the app cannot register this patient', function () {
    $w = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    $url = "/api/v1/walk-ins/join-preview?doctor_service_id={$w['service']}";

    expect(wi_json($url, $this->headers)->json('data'))->toMatchArray(['state' => 'NOT_STARTED', 'accepts_remote_registration' => false, 'remote_refusal_reason_code' => 'remote_registration_off']);
    walkin_policy($w['manager'], $w['clinic'], $w['assignment'], ['allow_patient_app_registration' => true])->assertOk();
    $started = wi_json($url, $this->headers)->json('data');
    expect($started)->toMatchArray(['state' => 'NOT_STARTED', 'remote_refusal_reason_code' => 'queue_not_started', 'estimate' => null]);
    [, , $unverified] = remote_patient(verified: false);
    expect(wi_json($url, $unverified)->json('data.remote_refusal_reason_code'))->toBe('mobile_not_verified');
});

it('flags a range that reaches closing time on the preview (E1)', function () {
    walkin_policy($this->w['manager'], $this->w['clinic'], $this->w['assignment'], ['cutoff_minutes_before_close' => 0])->assertOk();
    $this->clock->at = next_monday_at('11:40');

    expect(wi_json("/api/v1/walk-ins/join-preview?doctor_service_id={$this->svc}", $this->headers)->assertOk()->json('data'))->toMatchArray(['requires_late_risk_acknowledgement' => true]);
});

it('refuses a preview for an unknown service, a role without patient rights, and nobody', function () {
    wi_json('/api/v1/walk-ins/join-preview?doctor_service_id='.Str::uuid7(), $this->headers)->assertStatus(422);
    wi_json('/api/v1/walk-ins/join-preview', $this->headers)->assertStatus(422);
    wi_json("/api/v1/walk-ins/join-preview?doctor_service_id={$this->svc}", session_for(account(Role::FinanceOfficer)))->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/walk-ins/join-preview?doctor_service_id={$this->svc}")->assertUnauthorized();
});
