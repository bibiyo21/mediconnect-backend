<?php

use App\Modules\Doctors\Models\DoctorService;
use App\Modules\WalkIns\Estimation\DurationSource;
use App\Modules\WalkIns\Services\QueueEstimates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 5.8-N (HAG-30 m7): QueueEstimates keeps no duration memo across calls. Two reads on one instance (the route's instance)
 * see a changed rolling average, so a stale estimate cannot be served after a new completed consultation is recorded.
 */

/** A completed walk-in consultation of $seconds, ended (1 + $n) hours ago, for the doctor's service. */
function qef_visit(array $w, int $seconds, int $n): void
{
    $ended = CarbonImmutable::now('UTC')->subHours(1 + $n);
    $queue = (string) Str::uuid();
    $entry = (string) Str::uuid();
    $staff = DB::table('users')->orderBy('created_at')->value('id');
    DB::table('walkin_queues')->insert(['id' => $queue, 'clinic_location_id' => $w['location'], 'doctor_profile_id' => $w['doctor']->id, 'clinic_service_id' => null,
        'local_date' => $ended->subDays(10 + $n)->toDateString(), 'status' => 'OPEN', 'last_number' => 1]);
    DB::table('walkin_queue_entries')->insert(['id' => $entry, 'walkin_queue_id' => $queue, 'queue_number' => 1, 'guest_name_encrypted' => 'x', 'registered_by_user_id' => $staff,
        'registration_channel' => 'STAFF_CONSOLE', 'status' => 'COMPLETED', 'assigned_doctor_profile_id' => $w['doctor']->id, 'payer_type' => 'SELF_PAY', 'completed_at' => $ended,
        'doctor_service_id' => $w['service'], 'privacy_notice_version' => '2026-11', 'privacy_notice_shown_by_user_id' => $staff, 'privacy_notice_shown_at' => $ended]);
    DB::table('consultations')->insert(['id' => (string) Str::uuid(), 'walkin_queue_entry_id' => $entry, 'doctor_profile_id' => $w['doctor']->id, 'clinic_location_id' => $w['location'],
        'consultation_type' => 'IN_PERSON', 'status' => 'COMPLETED', 'started_at' => $ended->subSeconds($seconds), 'ended_at' => $ended, 'duration_seconds' => $seconds]);
}

beforeEach(function () {
    seed_roles();
    config(['walkins.estimation' => ['window_size' => 20, 'trim_fraction' => 0.2, 'min_samples' => 3, 'max_age_days' => 180, 'default_minutes' => 15,
        'range_spread_percent' => 25, 'min_range_width_minutes' => 5, 'max_lateness_minutes' => 30]]);
    $this->w = walkin_world(['remote' => true]);
    $this->service = DoctorService::query()->findOrFail($this->w['service']);
});

it('two duration reads on one QueueEstimates instance: the second sees the new rolling average once enough consultations are recorded', function () {
    $estimates = app(QueueEstimates::class);
    $doctor = (string) $this->w['doctor']->id;

    $before = $estimates->duration($doctor, $this->service->id);
    expect($before->source)->toBe(DurationSource::Service);

    foreach ([600, 600, 600] as $n => $seconds) {
        qef_visit($this->w, $seconds, $n);
    }

    $after = $estimates->duration($doctor, $this->service->id);
    expect($after->source)->toBe(DurationSource::Rolling)->and($after->seconds)->toBe(600)
        ->and($after->seconds)->not->toBe($before->seconds);
});
