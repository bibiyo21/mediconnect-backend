<?php

use App\Modules\Discovery\Jobs\IndexDoctorSearchDocument;
use App\Modules\Discovery\Services\DoctorDocuments;
use App\Modules\Discovery\Services\SearchCache;
use App\Modules\Discovery\Services\SearchIndexer;
use App\Modules\Scheduling\Services\AvailabilityProjector;
use App\Shared\Contracts\SearchEngine;
use Carbon\CarbonImmutable;
use Database\Seeders\SchedulingPolicySeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 4.8: availability in search. Documents carry advisory open times
 * from the projection (policy applied); the API filters by Philippine date and
 * time and sorts by next availability. A projection rebuild re-indexes the
 * doctor. Booking still revalidates (§11.6).
 */

beforeEach(function () {
    // Pin the clock: the doctor works Mondays from 09:00 and the index applies the booking notice, so on a
    // Monday morning the first OPEN slot in the table is not the first bookable one. The next Friday at 10:00,
    // computed from the real time, keeps this suite independent of when it runs, and the application clock
    // never trails the database's (a fixed past date would).
    $this->travelTo(CarbonImmutable::now('Asia/Manila')->next(CarbonImmutable::FRIDAY)->setTime(10, 0));
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    $this->seed(SchedulingPolicySeeder::class);
    $this->engine = app(SearchEngine::class);

    $this->location = directory_clinic();
    foreach (range(1, 6) as $day) {
        DB::table('clinic_operating_hours')->insert(['id' => (string) Str::uuid7(), 'clinic_location_id' => $this->location->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '17:00', 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $this->location);
    $this->other = directory_doctor('ACTIVE', 'Dr. Ben Reyes', $this->location);
    $service = DB::table('doctor_services')->where('doctor_profile_id', $this->doctor->id)->where('consultation_type', 'IN_PERSON')->value('id');
    $mode = (string) Str::uuid7();
    DB::table('doctor_service_modes')->insert(['id' => $mode, 'doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $this->location->id, 'doctor_service_id' => $service, 'consultation_type' => 'IN_PERSON', 'service_mode' => 'APPOINTMENT', 'effective_from' => '2026-01-01']);
    $this->schedule = (string) Str::uuid7();
    DB::table('doctor_schedules')->insert(['id' => $this->schedule, 'doctor_profile_id' => $this->doctor->id, 'name' => 'Mondays', 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_by' => $this->doctor->user_id]);
    // Mondays 09:00-12:00, 20-minute slots with 5-minute buffers: 09:00, 09:25 ... 11:30.
    DB::table('doctor_schedule_shifts')->insert(['id' => (string) Str::uuid7(), 'doctor_schedule_id' => $this->schedule, 'day_of_week' => 1, 'clinic_location_id' => $this->location->id, 'doctor_service_mode_id' => $mode, 'starts_at_local' => '09:00', 'ends_at_local' => '12:00', 'timezone' => 'Asia/Manila', 'slot_duration_minutes' => 20, 'buffer_minutes' => 5]);
    $this->monday = CarbonImmutable::now('Asia/Manila')->addDays(2)->next(CarbonImmutable::MONDAY)->toDateString();
    app(AvailabilityProjector::class)->refresh($this->doctor->id);
});

function in_person_doc(string $doctorId, string $locationId): array
{
    return app(SearchEngine::class)->documents(config('search.prefix').'_doctors')[$doctorId.':'.$locationId] ?? [];
}

function found(string $query): array
{
    return test()->getJson('/api/v1/search/doctors?'.$query)->assertOk()->json('data.*.doctor_id');
}

it('indexes open times from the projection after a refresh, and ranks a doctor with open times higher', function () {
    $doc = in_person_doc($this->doctor->id, $this->location->id);
    $first = (int) DB::table('doctor_availability_slots')->where('doctor_profile_id', $this->doctor->id)->where('status', 'OPEN')->min(DB::raw('extract(epoch from starts_at)'));

    expect($doc['available_dates'])->toContain($this->monday)
        ->and($doc['available_slot_keys'])->toContain(DoctorDocuments::slotKey($this->monday, 540))->toContain(DoctorDocuments::slotKey($this->monday, 690)) // 09:00 and 11:30
        ->and($doc['next_available_at'])->toBe($first)
        ->and($doc['rank_score'])->toBeGreaterThan(in_person_doc($this->other->id, $this->location->id)['rank_score'])
        ->and(in_person_doc($this->other->id, $this->location->id))->not->toHaveKey('available_dates');
    // Online has no schedule: its document has no open times.
    expect(app(SearchEngine::class)->documents(config('search.prefix').'_doctors')[$this->doctor->id.':online'])->not->toHaveKey('available_dates');

    $result = $this->getJson('/api/v1/search/doctors?q=santos')->json('data.0');
    expect($result['next_available_at'])->toBe(CarbonImmutable::createFromTimestamp($first)->toIso8601ZuluString())
        ->and($result['locations'][0]['next_available_at'])->toBe($result['next_available_at']);
});

it('filters by Philippine date, time, or both', function () {
    $tuesday = CarbonImmutable::parse($this->monday)->addDay()->toDateString();

    expect(found("available_date={$this->monday}"))->toBe([$this->doctor->id])
        ->and(found("available_date={$tuesday}"))->toBe([])
        ->and(found("available_date={$this->monday}&available_time_from=09:00&available_time_to=10:00"))->toBe([$this->doctor->id])
        ->and(found("available_date={$this->monday}&available_time_from=13:00&available_time_to=14:00"))->toBe([])
        ->and(found('available_time_from=11:30&available_time_to=11:31'))->toBe([$this->doctor->id])
        ->and(found('available_time_from=11:31'))->toBe([])
        ->and(found('available_time_to=09:00'))->toBe([]);
    $this->getJson('/api/v1/search/doctors?sort=next_available')->assertOk();
});

it('leaves out a fully booked day once the doctor is re-indexed', function () {
    DB::table('doctor_availability_slots')->where('doctor_profile_id', $this->doctor->id)->where('local_date', $this->monday)->update(['status' => 'BOOKED']);
    IndexDoctorSearchDocument::dispatchSync($this->doctor->id);

    expect(found("available_date={$this->monday}"))->toBe([]);
});

it('re-indexes when the projection is rebuilt', function () {
    DB::table('doctor_schedules')->where('id', $this->schedule)->update(['status' => 'ARCHIVED']);
    app(AvailabilityProjector::class)->refresh($this->doctor->id);

    expect(in_person_doc($this->doctor->id, $this->location->id))->not->toHaveKey('available_dates')
        ->and(found("available_date={$this->monday}"))->toBe([]);
});

it('never matches times on the day that have already passed', function () {
    $this->travelTo(CarbonImmutable::parse($this->monday.' 07:00', 'Asia/Manila'));
    app(AvailabilityProjector::class)->refresh($this->doctor->id);
    expect(found("available_date={$this->monday}"))->toBe([$this->doctor->id]);

    // The index still lists 09:00 to 11:30, but at 11:45 none of them can be reached.
    $this->travelTo(CarbonImmutable::parse($this->monday.' 11:45', 'Asia/Manila'));
    SearchCache::bump('doctors'); // a cached answer from 07:00 must not be reused
    expect(found("available_date={$this->monday}"))->toBe([])
        ->and($this->getJson('/api/v1/search/doctors?q=santos')->json('data.0.next_available_at'))->toBeNull();
    // Times alone: later Mondays still match, but today's passed 09:00 alone does not.
    expect(found('available_time_from=09:00&available_time_to=10:00'))->toBe([$this->doctor->id]);
    config(['search.availability_days' => 1]);
    SearchCache::bump('doctors');
    expect(found('available_time_from=09:00&available_time_to=10:00'))->toBe([]);
});

it('validates the availability filters', function () {
    $today = CarbonImmutable::now('Asia/Manila');
    $this->getJson('/api/v1/search/doctors?available_date='.$today->subDay()->toDateString())->assertStatus(422)->assertJsonValidationErrors('available_date');
    $this->getJson('/api/v1/search/doctors?available_date='.$today->addDays(30)->toDateString())->assertStatus(422)->assertJsonValidationErrors('available_date');
    $this->getJson('/api/v1/search/doctors?available_date=next-monday')->assertStatus(422);
    $this->getJson('/api/v1/search/doctors?available_time_from=10:00&available_time_to=09:00')->assertStatus(422)->assertJsonValidationErrors('available_time_to');
    $this->getJson('/api/v1/search/doctors?available_time_to=09:00')->assertOk();
    $this->getJson('/api/v1/search/doctors?sort=soonest')->assertStatus(422);
});

it('filters and sorts by availability in the degraded fallback', function () {
    $this->engine->down = true;
    $response = $this->getJson("/api/v1/search/doctors?available_date={$this->monday}&available_time_from=09:00&available_time_to=10:00")->assertOk();

    expect($response->json('meta.degraded'))->toBeTrue()
        ->and($response->json('data.*.doctor_id'))->toBe([$this->doctor->id]);
    expect($this->getJson('/api/v1/search/doctors?sort=next_available')->json('data.0.doctor_id'))->toBe($this->doctor->id);
});

it('sorts the degraded fallback by the first bookable slot on the application clock, not by name', function () {
    // Dr. Ben Reyes (after Dr. Ana Santos by name) works Mondays 08:00-09:00, so his first slot is the earlier one.
    $service = DB::table('doctor_services')->where('doctor_profile_id', $this->other->id)->where('consultation_type', 'IN_PERSON')->value('id');
    $mode = (string) Str::uuid7();
    DB::table('doctor_service_modes')->insert(['id' => $mode, 'doctor_profile_id' => $this->other->id, 'clinic_location_id' => $this->location->id, 'doctor_service_id' => $service, 'consultation_type' => 'IN_PERSON', 'service_mode' => 'APPOINTMENT', 'effective_from' => '2026-01-01']);
    $schedule = (string) Str::uuid7();
    DB::table('doctor_schedules')->insert(['id' => $schedule, 'doctor_profile_id' => $this->other->id, 'name' => 'Early Mondays', 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_by' => $this->other->user_id]);
    DB::table('doctor_schedule_shifts')->insert(['id' => (string) Str::uuid7(), 'doctor_schedule_id' => $schedule, 'day_of_week' => 1, 'clinic_location_id' => $this->location->id, 'doctor_service_mode_id' => $mode, 'starts_at_local' => '08:00', 'ends_at_local' => '09:00', 'timezone' => 'Asia/Manila', 'slot_duration_minutes' => 20, 'buffer_minutes' => 5]);
    app(AvailabilityProjector::class)->refresh($this->other->id);
    $this->engine->down = true;

    // On Friday both have open Mondays; Dr. Reyes' 08:00 is the first, against name order.
    expect($this->getJson('/api/v1/search/doctors?sort=next_available')->assertOk()->json('data.*.doctor_id'))->toBe([$this->other->id, $this->doctor->id]);

    // On the application clock, Monday 08:30 has passed Dr. Reyes' 08:00 (the database's own now() is still earlier than that Monday,
    // so a PostgreSQL now() would keep counting it): his first slot is a week later, and Dr. Santos' 09:00 comes first.
    $this->travelTo(CarbonImmutable::parse($this->monday.' 08:30', 'Asia/Manila'));
    SearchCache::bump('doctors');
    expect($this->getJson('/api/v1/search/doctors?sort=next_available')->assertOk()->json('data.*.doctor_id'))->toBe([$this->doctor->id, $this->other->id]);
});

it('filters and sorts by availability on live Typesense', function () {
    $live = live_engine();
    if ($live === null) {
        $this->markTestSkipped('Typesense is not reachable here.');
    }
    config(['search.prefix' => 'mc_test_live_'.strtolower(Str::random(5))]);
    app()->instance(SearchEngine::class, $live);
    SearchIndexer::forgetInstalled();
    $prefix = (string) config('search.prefix');

    try {
        IndexDoctorSearchDocument::dispatchSync($this->doctor->id);
        IndexDoctorSearchDocument::dispatchSync($this->other->id);

        expect(found("available_date={$this->monday}"))->toBe([$this->doctor->id])
            ->and(found("available_date={$this->monday}&available_time_from=09:00&available_time_to=10:00"))->toBe([$this->doctor->id])
            ->and(found("available_date={$this->monday}&available_time_from=13:00"))->toBe([])
            ->and(found('available_time_from=11:30&available_time_to=11:31'))->toBe([$this->doctor->id])
            ->and(found('sort=next_available'))->toBe([$this->doctor->id, $this->other->id]);
    } finally {
        foreach (['doctors', 'clinics', 'specialties'] as $name) {
            if (($target = $live->aliasTarget($prefix.'_'.$name)) !== null) {
                $live->dropCollection($target);
            }
        }
    }
});
