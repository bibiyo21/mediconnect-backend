<?php

use App\Modules\Doctors\Models\DoctorService;
use App\Modules\WalkIns\Estimation\DurationSource;
use App\Modules\WalkIns\Estimation\RollingDurationStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 5.4: RollingDurationStats reads completed consultations. None are created by the product yet
 * (5.6 and Phase 6), so rows are inserted directly; the fallbacks are what runs until then.
 * Increment 5.5a-1: walk-in history is matched by the entry's doctor_service_id (and its replaces_id chain).
 */

beforeEach(function () {
    seed_roles();
    config(['walkins.estimation' => ['window_size' => 20, 'trim_fraction' => 0.2, 'min_samples' => 3, 'max_age_days' => 180, 'default_minutes' => 15,
        'range_spread_percent' => 25, 'min_range_width_minutes' => 5, 'max_lateness_minutes' => 30]]);
    $this->w = presence_world();
    $this->doctor = $this->w['doctor'];
    $this->service = DoctorService::query()->where('doctor_profile_id', $this->doctor->id)->firstOrFail(); // 20 minutes, no clinic service
    $this->staff = patient_account();
    $this->patientProfile = DB::table('patient_profiles')->where('user_id', $this->staff->id)->value('id');
    $this->day = 0;

    /**
     * A completed walk-in consultation of $seconds that ended $daysAgo days ago, one queue per day. The entry is registered
     * for $serviceId (the doctor service of the entry, default: the world's service), which is what the history matches on.
     */
    $this->visit = function (string $doctorId, string $locationId, int $seconds, int $daysAgo, string $status = 'COMPLETED', ?string $clinicService = null, ?string $serviceId = null) {
        $ended = CarbonImmutable::now('UTC')->subDays($daysAgo);
        $queue = (string) Str::uuid();
        $entry = (string) Str::uuid();
        DB::table('walkin_queues')->insert(['id' => $queue, 'clinic_location_id' => $locationId, 'doctor_profile_id' => $doctorId, 'clinic_service_id' => $clinicService,
            'local_date' => $ended->subDays(++$this->day)->toDateString(), 'status' => 'OPEN', 'last_number' => 1]);
        DB::table('walkin_queue_entries')->insert(['id' => $entry, 'walkin_queue_id' => $queue, 'queue_number' => 1, 'guest_name_encrypted' => 'x', 'registered_by_user_id' => $this->staff->id,
            'registration_channel' => 'STAFF_CONSOLE', 'status' => 'COMPLETED', 'assigned_doctor_profile_id' => $doctorId, 'payer_type' => 'SELF_PAY', 'completed_at' => $ended,
            'doctor_service_id' => $serviceId ?? $this->service->id, 'privacy_notice_version' => '2026-11', 'privacy_notice_shown_by_user_id' => $this->staff->id, 'privacy_notice_shown_at' => $ended]);
        DB::table('consultations')->insert(['id' => (string) Str::uuid(), 'walkin_queue_entry_id' => $entry, 'doctor_profile_id' => $doctorId, 'clinic_location_id' => $locationId, 'consultation_type' => 'IN_PERSON',
            'status' => $status, 'started_at' => $ended->subSeconds($seconds), 'ended_at' => $ended, 'duration_seconds' => $seconds]);
    };

    /** Another in-person service of a doctor at a location (not the world's service). Returns its id. */
    $this->otherService = function (string $doctorId, string $locationId, string $name = 'Follow-up', ?string $clinicService = null) {
        $id = (string) Str::uuid();
        DB::table('doctor_services')->insert(['id' => $id, 'doctor_profile_id' => $doctorId, 'clinic_location_id' => $locationId, 'clinic_service_id' => $clinicService, 'name' => $name,
            'consultation_type' => 'IN_PERSON', 'duration_minutes' => 10, 'fee_amount' => 30000, 'currency' => 'PHP', 'status' => 'ACTIVE', 'effective_from' => now(), 'created_by' => $this->staff->id]);

        return $id;
    };

    /** A completed consultation of an appointment booked for the given doctor service. */
    $this->appointment = function (string $doctorServiceId, int $seconds, int $daysAgo) {
        $ended = CarbonImmutable::now('UTC')->subDays($daysAgo);
        $appointment = (string) Str::uuid();
        DB::table('appointments')->insert(['id' => $appointment, 'reference_code' => strtoupper(Str::random(10)), 'patient_profile_id' => $this->patientProfile, 'booked_by_user_id' => $this->staff->id,
            'doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $this->w['location'], 'doctor_service_id' => $doctorServiceId, 'doctor_service_mode_id' => $this->w['mode'],
            'consultation_type' => 'IN_PERSON', 'starts_at' => $ended->subSeconds($seconds), 'ends_at' => $ended, 'timezone' => 'Asia/Manila', 'status' => 'COMPLETED', 'payer_type' => 'SELF_PAY', 'fee_amount' => 65000]);
        DB::table('consultations')->insert(['id' => (string) Str::uuid(), 'appointment_id' => $appointment, 'doctor_profile_id' => $this->doctor->id, 'patient_profile_id' => $this->patientProfile,
            'clinic_location_id' => $this->w['location'], 'consultation_type' => 'IN_PERSON', 'status' => 'COMPLETED', 'started_at' => $ended->subSeconds($seconds), 'ended_at' => $ended, 'duration_seconds' => $seconds]);
    };

    /** A fee change: ends the given service row and starts a replacement that points back at it. Returns the new id. */
    $this->replace = function (string $serviceId) {
        $old = DB::table('doctor_services')->where('id', $serviceId)->first();
        $new = (string) Str::uuid();
        DB::table('doctor_services')->where('id', $serviceId)->update(['status' => 'ENDED', 'effective_until' => now()]);
        DB::table('doctor_services')->insert(['id' => $new, 'doctor_profile_id' => $old->doctor_profile_id, 'clinic_location_id' => $old->clinic_location_id, 'clinic_service_id' => $old->clinic_service_id,
            'name' => $old->name, 'consultation_type' => $old->consultation_type, 'duration_minutes' => $old->duration_minutes, 'buffer_minutes' => $old->buffer_minutes, 'fee_amount' => 70000,
            'currency' => 'PHP', 'status' => 'ACTIVE', 'effective_from' => now(), 'replaces_id' => $serviceId, 'created_by' => $this->staff->id]);

        return $new;
    };

    $this->stats = fn (?string $doctorId = null, ?string $serviceId = 'own') => app(RollingDurationStats::class)
        ->expectedFor($doctorId ?? $this->doctor->id, $serviceId === 'own' ? $this->service->id : $serviceId);
});

it('falls back to the service duration when there is no history', function () {
    $d = ($this->stats)();

    expect([$d->seconds, $d->source, $d->samples])->toBe([1200, DurationSource::Service, 0]);
});

it('falls back to the configured default when there is no service either', function () {
    $d = ($this->stats)(serviceId: null);

    expect([$d->seconds, $d->source])->toBe([900, DurationSource::Default]);
});

it('does not trust a service that belongs to another doctor', function () {
    [$other] = verified_doctor('0765432');
    $d = ($this->stats)($other->id);

    expect([$d->seconds, $d->source])->toBe([900, DurationSource::Default]);
});

it('keeps using the service duration until there are enough completed consultations', function () {
    ($this->visit)($this->doctor->id, $this->w['location'], 300, 1);
    ($this->visit)($this->doctor->id, $this->w['location'], 300, 2);

    expect(($this->stats)()->source)->toBe(DurationSource::Service);
});

it('uses a trimmed mean of completed consultations once there are enough', function () {
    foreach ([600, 600, 600, 6000, 60] as $i => $seconds) {
        ($this->visit)($this->doctor->id, $this->w['location'], $seconds, $i + 1);
    }
    $d = ($this->stats)();

    // Trim 20% (one) off each end of five: the 60 and the 6000 go.
    expect([$d->seconds, $d->source, $d->samples])->toBe([600, DurationSource::Rolling, 5]);
});

it('uses the median while there are fewer than five samples, so one outlier cannot skew it', function () {
    foreach ([600, 660, 7200] as $i => $seconds) {
        ($this->visit)($this->doctor->id, $this->w['location'], $seconds, $i + 1);
    }

    expect(($this->stats)()->seconds)->toBe(660);
});

it('looks only at the most recent window of consultations', function () {
    config(['walkins.estimation.window_size' => 3, 'walkins.estimation.trim_fraction' => 0.0]);
    foreach ([300, 600, 900, 9000, 9000] as $i => $seconds) { // newest first
        ($this->visit)($this->doctor->id, $this->w['location'], $seconds, $i + 1);
    }

    expect(($this->stats)()->seconds)->toBe(600);
});

it('ignores consultations older than the configured age', function () {
    foreach ([170, 200, 250] as $daysAgo) {
        ($this->visit)($this->doctor->id, $this->w['location'], 300, $daysAgo); // only the first is inside 180 days
    }
    config(['walkins.estimation.max_age_days' => 180, 'walkins.estimation.min_samples' => 1]);
    $d = ($this->stats)();

    expect([$d->source, $d->samples])->toBe([DurationSource::Rolling, 1]);

    config(['walkins.estimation.max_age_days' => 100]);
    expect(($this->stats)()->source)->toBe(DurationSource::Service);
});

it('ignores other doctors, other services of the doctor, unfinished consultations and zero-length ones', function () {
    [$other] = verified_doctor('0765432');
    $second = add_location($this->w['clinic'], $this->w['manager']);
    $otherDoctorService = ($this->otherService)($other->id, $this->w['location']);
    $secondLocationService = ($this->otherService)($this->doctor->id, $second, 'Second location');
    foreach ([1, 2, 3] as $i) {
        ($this->visit)($other->id, $this->w['location'], 99, $i, serviceId: $otherDoctorService);
        ($this->visit)($this->doctor->id, $second, 99, $i, serviceId: $secondLocationService);
    }
    ($this->visit)($this->doctor->id, $this->w['location'], 99, 4, 'CANCELLED');
    ($this->visit)($this->doctor->id, $this->w['location'], 0, 5);

    expect(($this->stats)()->source)->toBe(DurationSource::Service);

    // With no service given, all of the doctor's own completed consultations count (every service, every location).
    expect(($this->stats)(serviceId: null)->seconds)->toBe(99);
});

it('counts consultations of appointments booked for the service', function () {
    foreach ([500, 500, 500] as $i => $seconds) {
        ($this->appointment)($this->service->id, $seconds, $i + 1);
    }
    $d = ($this->stats)();

    expect([$d->seconds, $d->source, $d->samples])->toBe([500, DurationSource::Rolling, 3]);
});

it('keeps appointment history across a fee change, from either end of the replaces_id chain', function () {
    ($this->appointment)($this->service->id, 500, 3);
    ($this->appointment)($this->service->id, 500, 2);
    $second = ($this->replace)($this->service->id);        // fee change 1: second replaces the original
    ($this->appointment)($second, 500, 1);
    $third = ($this->replace)($second);                    // fee change 2

    // Asking about the newest row, a middle row or the oldest row sees all three consultations.
    foreach ([$third, $second, $this->service->id] as $id) {
        $d = ($this->stats)(serviceId: $id);
        expect([$d->seconds, $d->source, $d->samples])->toBe([500, DurationSource::Rolling, 3]);
    }
});

it('does not count appointments of a different service', function () {
    $other = DB::table('doctor_services')->where('id', $this->service->id)->first();
    $otherId = (string) Str::uuid();
    DB::table('doctor_services')->insert(['id' => $otherId, 'doctor_profile_id' => $other->doctor_profile_id, 'clinic_location_id' => $other->clinic_location_id, 'name' => 'Follow-up',
        'consultation_type' => 'IN_PERSON', 'duration_minutes' => 10, 'fee_amount' => 30000, 'currency' => 'PHP', 'status' => 'ACTIVE', 'effective_from' => now(), 'created_by' => $this->staff->id]);
    foreach ([1, 2, 3] as $i) {
        ($this->appointment)($otherId, 500, $i);
    }

    expect(($this->stats)()->source)->toBe(DurationSource::Service)
        ->and(($this->stats)(serviceId: $otherId)->source)->toBe(DurationSource::Rolling);
});

it('matches walk-ins by the doctor service the entry was registered for, not by clinic service or location', function () {
    $followUp = ($this->otherService)($this->doctor->id, $this->w['location']);
    foreach ([1, 2, 3] as $i) {
        ($this->visit)($this->doctor->id, $this->w['location'], 99, $i, serviceId: $followUp);
    }

    // Same doctor, same location, same (null) clinic service: the follow-up visits are not the first service's history.
    expect(($this->stats)()->source)->toBe(DurationSource::Service);

    foreach ([4, 5, 6] as $i) {
        ($this->visit)($this->doctor->id, $this->w['location'], 700, $i);
    }
    $own = ($this->stats)();
    $other = ($this->stats)(serviceId: $followUp);
    expect([$own->seconds, $own->source, $own->samples])->toBe([700, DurationSource::Rolling, 3])
        ->and([$other->seconds, $other->source, $other->samples])->toBe([99, DurationSource::Rolling, 3]);

    // The queue's clinic service does not decide history either: a Vaccination-queue service has its own entries, counted for it alone.
    $clinicService = (string) Str::uuid();
    DB::table('clinic_services')->insert(['id' => $clinicService, 'clinic_location_id' => $this->w['location'], 'name' => 'Vaccination']);
    $vaccination = ($this->otherService)($this->doctor->id, $this->w['location'], 'Vaccination', $clinicService);
    foreach ([7, 8, 9] as $i) {
        ($this->visit)($this->doctor->id, $this->w['location'], 300, $i, clinicService: $clinicService, serviceId: $vaccination);
    }
    expect(($this->stats)(serviceId: $vaccination)->seconds)->toBe(300)
        ->and(($this->stats)(serviceId: $followUp)->samples)->toBe(3)
        ->and(($this->stats)()->samples)->toBe(3);
});

it('keeps walk-in history across a fee change, from either end of the replaces_id chain', function () {
    // Days ago are spread out: each visit gets its own queue day (the helper subtracts a running counter too).
    ($this->visit)($this->doctor->id, $this->w['location'], 500, 5);
    $second = ($this->replace)($this->service->id);
    ($this->visit)($this->doctor->id, $this->w['location'], 500, 3, serviceId: $second);
    $third = ($this->replace)($second);
    ($this->visit)($this->doctor->id, $this->w['location'], 500, 1, serviceId: $third);

    foreach ([$third, $second, $this->service->id] as $id) {
        $d = ($this->stats)(serviceId: $id);
        expect([$d->seconds, $d->source, $d->samples])->toBe([500, DurationSource::Rolling, 3]);
    }
});

it('counts appointment and walk-in consultations of the same service together', function () {
    ($this->appointment)($this->service->id, 400, 1);
    ($this->visit)($this->doctor->id, $this->w['location'], 600, 2);
    ($this->visit)($this->doctor->id, $this->w['location'], 800, 3);
    $d = ($this->stats)();

    expect([$d->seconds, $d->source, $d->samples])->toBe([600, DurationSource::Rolling, 3]);
});
