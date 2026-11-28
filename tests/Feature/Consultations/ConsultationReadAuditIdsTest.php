<?php

use App\Modules\Rbac\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A completed walk-in consultation at the world's location (two of them make the page non-trivial). */
function cra_visit(array $w, int $n, ?string $patientProfile = null): void
{
    $ended = CarbonImmutable::now('UTC')->subHours(1 + $n);
    $queue = (string) Str::uuid();
    $entry = (string) Str::uuid();
    $staff = DB::table('users')->orderBy('created_at')->value('id');
    DB::table('walkin_queues')->insert(['id' => $queue, 'clinic_location_id' => $w['location'], 'doctor_profile_id' => $w['doctor']->id, 'clinic_service_id' => null,
        'local_date' => $ended->subDays(10 + $n)->toDateString(), 'status' => 'OPEN', 'last_number' => 1]);
    DB::table('walkin_queue_entries')->insert(['id' => $entry, 'walkin_queue_id' => $queue, 'queue_number' => 1, 'patient_profile_id' => $patientProfile, 'guest_name_encrypted' => $patientProfile === null ? 'x' : null, 'registered_by_user_id' => $staff,
        'registration_channel' => 'STAFF_CONSOLE', 'status' => 'COMPLETED', 'assigned_doctor_profile_id' => $w['doctor']->id, 'payer_type' => 'SELF_PAY', 'completed_at' => $ended,
        'doctor_service_id' => $w['service'], 'privacy_notice_version' => '2026-11', 'privacy_notice_shown_by_user_id' => $staff, 'privacy_notice_shown_at' => $ended]);
    DB::table('consultations')->insert(['id' => (string) Str::uuid(), 'walkin_queue_entry_id' => $entry, 'patient_profile_id' => $patientProfile, 'doctor_profile_id' => $w['doctor']->id, 'clinic_location_id' => $w['location'],
        'consultation_type' => 'IN_PERSON', 'status' => 'COMPLETED', 'started_at' => $ended->subMinutes(10), 'ended_at' => $ended, 'duration_seconds' => 600]);
}

/*
 * Increment 5.8-N (HAG-30): the global list audit row (`consultations.listed`) records exactly the consultation ids the page returned, and
 * nothing else that identifies a patient. The ids are read back from the response, so a drift between the page and the audit fails here.
 */

beforeEach(function () {
    seed_roles();
    $this->w = walkin_world(['remote' => true]);
    [, $profileA] = remote_patient();
    [, $profileB] = remote_patient();
    $this->patientProfiles = [$profileA, $profileB];
    // 21 visits, one more than a page holds: the two newest are patients', the rest guests. The page keeps 20, so the oldest guest drops off.
    cra_visit($this->w, 0, $profileA);
    cra_visit($this->w, 1, $profileB);
    for ($n = 2; $n <= 20; $n++) {
        cra_visit($this->w, $n);
    }
});

it('the global list audit holds consultation_ids equal to the ids of the page (20 of 21), and no patient profile id', function () {
    $headers = admin_session(Role::Admin)[1];

    $response = $this->getJson('/api/v1/consultations', $headers)->assertOk();
    $returned = collect($response->json('data'))->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();

    $audit = DB::table('audit_logs')->where('action', 'consultations.listed')->latest('occurred_at')->first();
    expect($audit)->not->toBeNull();
    $ids = collect(json_decode((string) $audit->changes, true)['consultation_ids'] ?? [])->map(fn ($id) => (string) $id)->sort()->values()->all();

    expect($returned)->toHaveCount(20)->and($ids)->toBe($returned)->and((bool) $audit->is_access_event)->toBeTrue()
        ->and((string) $audit->changes)->not->toContain('patient');
    foreach ($this->patientProfiles as $profile) {
        expect((string) $audit->changes)->not->toContain($profile);
    }
});
