<?php

use App\Modules\Scheduling\Contracts\WalkInDay;
use App\Modules\WalkIns\Jobs\PurgeGuestWalkInPii;
use App\Shared\Logging\RedactSensitiveData;
use App\Shared\Logging\SentryScrubber;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sentry\Event;

/*
 * Increment 5.5a-2, regressions from the code and security reviews: guardian access judged now, the search body, active doctor and
 * clinic, privacy notice versions, replays after rules change, protected appointments inside their grace, a late doctor, board
 * cost and dates, throttling before idempotency, and the rules shared by registration and the join preview.
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
});

// 1. Guardian access is judged now.

it('takes a revoked guardian\'s access to the entry away at once: show, lists and cancel (Sec F2)', function () {
    doctor_in($this->w);
    $dependant = wi_dependent($this->headers);
    $id = register_walkin($this->headers, patient_body($this->svc, $dependant))->assertCreated()->json('data.id');
    wi_json("/api/v1/walk-ins/{$id}", $this->headers)->assertOk();

    DB::table('dependents')->where('dependent_patient_profile_id', $dependant)->update(['authorization_status' => 'REVOKED', 'revoked_at' => now()]);

    wi_json("/api/v1/walk-ins/{$id}", $this->headers)->assertNotFound();
    expect(wi_json('/api/v1/walk-ins/queue', $this->headers)->assertOk()->json('data'))->toBe([]);
    wi_post("{$id}/cancel", $this->headers, ['reason_code' => 'PATIENT_REQUEST'])->assertNotFound();
    expect(DB::table('walkin_queue_entries')->where('id', $id)->value('status'))->toBe('REGISTERED');

    // Staff cancel: no notice reaches the revoked guardian.
    wi_post("{$id}/cancel", $this->staff, ['reason_code' => 'OTHER'])->assertOk();
    expect(DB::table('notifications')->where('type', 'walkins.cancelled')->count())->toBe(0);
});

it('shows and notifies every guardian still authorized, whoever registered the entry', function () {
    doctor_in($this->w);
    $dependant = wi_dependent($this->headers);
    [$second, $secondProfile, $secondHeaders] = remote_patient();
    $link = DB::table('dependents')->where('dependent_patient_profile_id', $dependant)->first();
    DB::table('dependents')->insert(['id' => (string) Str::uuid7(), 'guardian_patient_profile_id' => $secondProfile, 'dependent_patient_profile_id' => $dependant, 'relationship' => 'PARENT',
        'authorization_status' => 'AUTHORIZED', 'authorized_at' => now(), 'consent_record_id' => $link->consent_record_id, 'created_at' => now(), 'updated_at' => now()]);
    $id = register_walkin($this->headers, patient_body($this->svc, $dependant))->assertCreated()->json('data.id');

    wi_json("/api/v1/walk-ins/{$id}", $secondHeaders)->assertOk();
    expect(wi_json('/api/v1/walk-ins/queue', $secondHeaders)->json('data.*.id'))->toBe([$id]);

    wi_post("{$id}/cancel", $this->staff, ['reason_code' => 'OTHER'])->assertOk();
    expect(DB::table('notifications')->where('type', 'walkins.cancelled')->pluck('notifiable_id')->sort()->values()->all())->toBe(collect([$this->user->id, $second->id])->sort()->values()->all());
});

it('judges "today" in the location\'s time zone for a patient\'s own list', function () {
    doctor_in($this->w);
    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated();
    // 17:00 UTC the day before is already after midnight in Manila: the entry is today's there.
    $this->clock->at = $this->clock->now()->setTimezone('Asia/Manila')->startOfDay()->subHours(2)->utc();
    expect(wi_json('/api/v1/walk-ins/queue', $this->headers)->json('data'))->toBe([]);
    $this->clock->at = $this->clock->now()->addHours(3);
    expect(wi_json('/api/v1/walk-ins/queue', $this->headers)->json('data'))->toHaveCount(1);
});

// 2. The search term never travels in a URL.

it('searches with a JSON body, not a URL, and keeps the term out of Sentry\'s request data', function () {
    register_walkin($this->staff, guest_body($this->svc, 'Zelda Quirke', ['guest' => ['name' => 'Zelda Quirke', 'phone' => '09179998888']]))->assertCreated();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/walk-ins/search?clinic_location_id={$this->w['location']}&term=09179998888", $this->staff)->assertStatus(405);

    wi_search($this->w['location'], '09179998888', $this->staff)->assertOk()->assertJsonCount(1, 'data');
    $request = $this->app['request'];
    expect($request->fullUrl())->not->toContain('9179998888')->and($request->getQueryString())->toBeNull();

    $event = Event::createEvent();
    $event->setRequest(['url' => $request->fullUrl(), 'query_string' => (string) $request->getQueryString(), 'data' => $request->all()]);
    $sent = json_encode(SentryScrubber::beforeSend($event)->getRequest());
    expect($sent)->not->toContain('9179998888')->not->toContain('Quirke');
    expect(RedactSensitiveData::scrubArray(['term' => '0917', 'search_term_count' => 3]))->toBe(['term' => '[REDACTED]', 'search_term_count' => 3]);
});

it('marks a name search that hit the row cap as possibly incomplete', function () {
    register_walkin($this->staff, guest_body($this->svc, 'Maria One', ['guest' => ['name' => 'Maria One']]))->assertCreated();
    register_walkin($this->staff, guest_body($this->svc, 'Maria Two', ['guest' => ['name' => 'Maria Two']]))->assertCreated();

    expect(wi_search($this->w['location'], 'maria', $this->staff)->json('meta.truncated'))->toBeFalse();
    config(['walkins.search_max_rows' => 2]);
    expect(wi_search($this->w['location'], 'maria', $this->staff)->json('meta.truncated'))->toBeTrue();
});

// 3. Active doctor and clinic.

it('refuses registration and previews for a doctor or clinic that is not active, on both paths (Sec F3)', function () {
    doctor_in($this->w);
    $preview = fn () => wi_json("/api/v1/walk-ins/join-preview?doctor_service_id={$this->svc}", $this->headers)->assertOk()->json('data');

    DB::table('doctor_profiles')->where('id', $this->w['doctor']->id)->update(['onboarding_status' => 'VERIFIED']);
    foreach ([register_walkin($this->staff, guest_body($this->svc)), register_walkin($this->headers, patient_body($this->svc, $this->profile))] as $r) {
        $r->assertStatus(409)->assertJsonPath('code', 'REGISTRATION_NOT_ALLOWED')->assertJsonPath('reason_code', 'doctor_not_active');
    }
    expect($preview())->toMatchArray(['state' => 'CLOSED', 'state_reason_code' => 'doctor_not_active', 'accepts_remote_registration' => false, 'estimate' => null]);
    DB::table('doctor_profiles')->where('id', $this->w['doctor']->id)->update(['onboarding_status' => 'ACTIVE']);

    DB::table('clinics')->where('id', $this->w['clinic'])->update(['status' => 'INACTIVE']);
    foreach ([register_walkin($this->staff, guest_body($this->svc)), register_walkin($this->headers, patient_body($this->svc, $this->profile))] as $r) {
        $r->assertStatus(409)->assertJsonPath('reason_code', 'location_not_active');
    }
    expect($preview()['state_reason_code'])->toBe('location_not_active');
    DB::table('clinics')->where('id', $this->w['clinic'])->update(['status' => 'ACTIVE']);
    DB::table('clinic_locations')->where('id', $this->w['location'])->update(['status' => 'INACTIVE']);
    register_walkin($this->staff, guest_body($this->svc))->assertStatus(409)->assertJsonPath('reason_code', 'location_not_active');
    expect(DB::table('walkin_queues')->count())->toBe(0);
});

// 6. Privacy notice versions.

it('accepts only a published privacy notice version and records it on the audit row', function () {
    register_walkin($this->staff, guest_body($this->svc, extra: ['privacy_notice_version' => 'v9']))->assertStatus(422)->assertJsonValidationErrorFor('privacy_notice_version', 'errors');

    config(['walkins.privacy_notice.published_versions' => ['v1', 'v2']]);
    register_walkin($this->staff, guest_body($this->svc, extra: ['privacy_notice_version' => 'v2']))->assertCreated();
    expect(json_decode(DB::table('audit_logs')->where('action', 'walkins.registered')->sole()->changes, true)['privacy_notice_version'])->toBe('v2');
});

// 8. Replays are answered before the rules, and the body is compared in full.

it('returns the original entry for a replay after the clinic turned remote registration off or the service ended', function () {
    doctor_in($this->w);
    $key = (string) Str::uuid7();
    $first = register_walkin($this->headers, patient_body($this->svc, $this->profile), $key)->assertCreated()->json('data.id');
    DB::table('idempotency_keys')->delete();

    walkin_policy($this->w['manager'], $this->w['clinic'], $this->w['assignment'], ['allow_patient_app_registration' => false])->assertOk();
    expect(register_walkin($this->headers, patient_body($this->svc, $this->profile), $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->json('data.id'))->toBe($first);

    DB::table('doctor_services')->where('id', $this->svc)->update(['status' => 'ENDED', 'effective_until' => now()]);
    expect(register_walkin($this->headers, patient_body($this->svc, $this->profile), $key)->assertCreated()->json('data.id'))->toBe($first);
    expect(DB::table('walkin_queue_entries')->count())->toBe(1);
});

it('compares priority, the HMO name and the capacity override before it replays an entry', function () {
    $key = (string) Str::uuid7();
    register_walkin($this->staff, guest_body($this->svc, extra: ['payer_type' => 'HMO', 'hmo_card_name_text' => 'Maxicare', 'priority_level' => 'PRIORITY', 'priority_reason_code' => 'PREGNANT']), $key)->assertCreated();
    $same = fn (array $extra) => guest_body($this->svc, extra: ['payer_type' => 'HMO', 'hmo_card_name_text' => 'Maxicare', 'priority_level' => 'PRIORITY', 'priority_reason_code' => 'PREGNANT', ...$extra]);

    foreach ([['priority_level' => 'URGENT'], ['priority_reason_code' => 'OTHER'], ['hmo_card_name_text' => 'Medicard'], ['capacity_override' => true]] as $change) {
        DB::table('idempotency_keys')->delete();
        register_walkin($this->staff, $same($change), $key)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }
    DB::table('idempotency_keys')->delete();
    register_walkin($this->staff, $same([]), $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect(DB::table('walkin_queue_entries')->count())->toBe(1);
});

it('replays a guest entry without a phone after the purge', function () {
    $key = (string) Str::uuid7();
    $body = ['doctor_service_id' => $this->svc, 'guest' => ['name' => 'Plain Guest'], 'payer_type' => 'SELF_PAY', 'privacy_notice_version' => 'v1'];
    $first = register_walkin($this->staff, $body, $key)->assertCreated()->json('data.id');
    DB::table('walkin_queue_entries')->update(['created_at' => $this->clock->now()->subDays(40)]);
    (new PurgeGuestWalkInPii)->handle($this->clock);
    DB::table('idempotency_keys')->delete();

    expect(register_walkin($this->staff, $body, $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->json('data.id'))->toBe($first);
});

// 10. An appointment is still protected inside its grace.

it('keeps an appointment that has started and ended protected until its late grace has passed (m2d)', function () {
    doctor_in($this->w);
    $now = $this->clock->now();
    wi_appointment($this->w, $now->subMinutes(10), $now->subMinutes(5)); // 09:50-09:55, now 10:00, grace 15 minutes

    $day = app(WalkInDay::class)->forDoctorAtLocation($this->w['doctor']->id, $this->w['location'], $now->setTimezone('Asia/Manila')->toDateString());
    expect($day->appointments)->toHaveCount(1)->and($day->lateGraceMinutes)->toBe(15);
    $r = register_walkin($this->staff, guest_body($this->svc))->assertCreated();
    expect($r->json('data.estimate.basis'))->toContain('protected_appointment')->and($r->json('data.estimate.min_minutes'))->toBeGreaterThanOrEqual(5);

    config(['walkins.estimation.appointment_grace_minutes' => 3]); // grace over at 09:53: the visit ended after that, so the 09:55 end still counts, but 10:00 does not
    DB::table('appointments')->update(['starts_at' => $now->subMinutes(30), 'ends_at' => $now->subMinutes(20)]);
    $gone = app(WalkInDay::class)->forDoctorAtLocation($this->w['doctor']->id, $this->w['location'], $now->setTimezone('Asia/Manila')->toDateString());
    expect($gone->appointments)->toHaveCount(0);
});

// 11. A doctor who is late.

it('gives an unknown estimate for a doctor not in partway through a shift, and a known one before the first shift (decision 179)', function () {
    wi_schedule($this->w, [['starts_at' => '09:00', 'ends_at' => '11:00'], ['starts_at' => '13:00', 'ends_at' => '15:00']]);

    $late = register_walkin($this->staff, guest_body($this->svc))->assertCreated(); // 10:00, inside the first shift, not checked in
    expect($late->json('data.estimate.wait_status'))->toBe('UNKNOWN')->and($late->json('data.estimate.basis'))->toContain('unknown');

    $this->clock->at = next_monday_at('08:30');
    $early = register_walkin($this->staff, guest_body($this->svc, 'Early Guest', ['guest' => ['name' => 'Early Guest']]))->assertCreated();
    expect($early->json('data.estimate.basis'))->not->toContain('unknown')->and($early->json('data.estimate.min_minutes'))->not->toBeNull();
});

// 12 and 15 are covered in QueueActionsTest and WalkInReadTest. 14. Board cost.

it('keeps the cost of the staff board nearly flat as queues are added', function () {
    doctor_in($this->w);
    register_walkin($this->staff, guest_body($this->svc))->assertCreated();
    $count = function () {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        wi_json("/api/v1/walk-ins/queue?clinic_location_id={$this->w['location']}", $this->staff)->assertOk();

        return $n;
    };
    $one = $count();
    foreach (range(1, 3) as $i) {
        wi_seed_entry($this->w, null, 'WAITING', null, 'STAFF_CONSOLE');
    }
    $four = $count();

    // Each extra queue costs its own schedule facts (a fixed handful of queries) and nothing per entry or per audience.
    expect(($four - $one) / 3)->toBeLessThan(40)->and($four)->toBeLessThan(200);
});

// 19. Board dates.

it('reads today and yesterday freely and audits any older date', function () {
    $board = fn (string $date) => wi_json("/api/v1/walk-ins/queue?clinic_location_id={$this->w['location']}&date={$date}", $this->staff)->assertOk();
    $today = $this->clock->now()->setTimezone('Asia/Manila');

    $board($today->toDateString());
    $board($today->subDay()->toDateString());
    expect(DB::table('audit_logs')->where('action', 'walkins.board_read')->count())->toBe(0);

    $old = $today->subDays(9)->toDateString();
    $board($old);
    $audit = DB::table('audit_logs')->where('action', 'walkins.board_read')->sole();
    expect(json_decode($audit->changes, true))->toMatchArray(['historical_date' => $old, 'audience' => 'STAFF'])->and((bool) $audit->is_access_event)->toBeTrue();
});

// 18. Throttle before idempotency.

it('does not store or replay a throttled answer', function () {
    config(['walkins.limits.register_staff_per_minute' => 1]);
    register_walkin($this->staff, guest_body($this->svc))->assertCreated();
    $key = (string) Str::uuid7();

    register_walkin($this->staff, guest_body($this->svc, 'Second Guest', ['guest' => ['name' => 'Second Guest']]), $key)->assertStatus(429);
    expect(DB::table('idempotency_keys')->where('key', $key)->count())->toBe(0);

    config(['walkins.limits.register_staff_per_minute' => 60]);
    register_walkin($this->staff, guest_body($this->svc, 'Second Guest', ['guest' => ['name' => 'Second Guest']]), $key)->assertCreated();
});

// 17. The purge index.

it('has a partial index for the purge scan', function () {
    $def = (string) DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'ix_entry_guest_purge'")?->indexdef;

    expect($def)->toContain('created_at')->toContain('patient_profile_id IS NULL')->toContain('guest_pii_purged_at IS NULL');
});

// 15. The preview follows registration.

it('previews exactly what registration would answer, in the same order', function () {
    doctor_in($this->w);
    $preview = fn () => wi_json("/api/v1/walk-ins/join-preview?doctor_service_id={$this->svc}", $this->headers)->assertOk()->json('data');
    $register = fn () => register_walkin($this->headers, patient_body($this->svc, $this->profile));

    // Clinic closed beats the paused queue; both beat the cutoff.
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/clinics/{$this->w['clinic']}/holidays", ['date' => $this->clock->now()->setTimezone('Asia/Manila')->toDateString(), 'name' => 'Day off', 'is_closed' => true], $this->w['manager']);
    expect($preview()['remote_refusal_reason_code'])->toBe('clinic_closed_today');
    $register()->assertStatus(409)->assertJsonPath('reason_code', 'clinic_closed_today');
    DB::table('clinic_holidays')->delete();

    $this->clock->at = next_monday_at('11:45');
    expect($preview()['remote_refusal_reason_code'])->toBe('cutoff_passed');
    $register()->assertStatus(409)->assertJsonPath('reason_code', 'cutoff_passed');
});

it('lists a patient\'s entries for an explicit date well in the past', function () {
    doctor_in($this->w);
    $id = register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    $old = $this->clock->now()->setTimezone('Asia/Manila')->subDays(5)->toDateString();
    DB::table('walkin_queues')->update(['local_date' => $old]);

    expect(wi_json("/api/v1/walk-ins/queue?date={$old}", $this->headers)->assertOk()->json('data.*.id'))->toBe([$id])
        ->and(wi_json('/api/v1/walk-ins/queue', $this->headers)->json('data'))->toBe([]);
});

it('previews CLOSED for a queue past its cutoff even while the doctor is away, and keeps registration\'s reason', function () {
    doctor_in($this->w);
    presence_post('unavailable', $this->w['doctorHeaders'], ['reason' => 'PHONE_CALL'])->assertOk();
    $this->clock->at = next_monday_at('11:45');

    $p = wi_json("/api/v1/walk-ins/join-preview?doctor_service_id={$this->svc}", $this->headers)->assertOk()->json('data');

    expect($p)->toMatchArray(['state' => 'CLOSED', 'state_reason_code' => 'cutoff_passed', 'accepts_remote_registration' => false, 'remote_refusal_reason_code' => 'doctor_unavailable']);
    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertStatus(409)->assertJsonPath('reason_code', 'doctor_unavailable');
});
