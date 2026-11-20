<?php

use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Discovery\Jobs\IndexDoctorSearchDocument;
use App\Modules\Discovery\Services\DirectoryDocuments;
use App\Modules\Discovery\Services\DoctorDocuments;
use App\Modules\Discovery\Services\SearchIndexer;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Doctors\Models\DoctorSpecialty;
use App\Modules\Specialties\Models\Specialty;
use App\Shared\Contracts\SearchEngine;
use App\Shared\Search\SearchUnavailable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 3.2: outbox-driven indexing. Writes record directory events in the
 * same transaction; the relay queues index jobs that rebuild documents from
 * PostgreSQL. Tests run the queue synchronously against the in-memory engine.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    $this->engine = app(SearchEngine::class);
});

function doctor_docs(DoctorProfile $doctor): array
{
    return array_filter(app(SearchEngine::class)->documents('mc_test_doctors'), fn (array $d) => $d['doctor_id'] === $doctor->id);
}

it('indexes an ACTIVE doctor per practice location and online, from the outbox, with public data only', function () {
    $location = directory_clinic();
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);

    $docs = doctor_docs($doctor);

    expect(array_keys($docs))->toEqualCanonicalizing([$doctor->id.':'.$location->id, $doctor->id.':online']);
    $inPerson = $docs[$doctor->id.':'.$location->id];
    expect($inPerson['clinic_name'])->toBe('Santos Family Clinic')
        ->and($inPerson['fee_min'])->toBe(65000)
        ->and($inPerson['consultation_modes'])->toBe(['IN_PERSON'])
        ->and($inPerson['specialties'])->toHaveCount(1) // the self-reported one is left out (D-40)
        ->and($docs[$doctor->id.':online']['consultation_modes'])->toBe(['ONLINE'])
        ->and(DB::table('outbox_events')->where('event_type', 'doctors.directory_changed')->where('aggregate_id', $doctor->id)->exists())->toBeTrue();
    $json = json_encode($docs);
    foreach (['prc_license', $doctor->prc_license_number_encrypted, 'user_id', 'email', 'onboarding_status', 'biography'] as $private) {
        expect($json)->not->toContain((string) $private);
    }
});

it('never indexes doctors who are not ACTIVE, and removes them when they leave ACTIVE', function () {
    $location = directory_clinic();
    $verified = directory_doctor('VERIFIED', 'Dr. Not Yet', $location);
    $active = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
    expect(doctor_docs($verified))->toBe([])->and(doctor_docs($active))->not->toBe([]);

    $active->forceFill(['onboarding_status' => 'REJECTED', 'license_verification_status' => 'REJECTED'])->save();

    expect(doctor_docs($active))->toBe([]);
});

it('drops the location document when an assignment ends, keeping the online one', function () {
    $location = directory_clinic();
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);

    DoctorClinicAssignment::where('doctor_profile_id', $doctor->id)->firstOrFail()->forceFill(['status' => 'ENDED', 'effective_until' => now()])->save();

    expect(array_keys(doctor_docs($doctor)))->toBe([$doctor->id.':online']);
});

it('updates doctor documents when the clinic is renamed, and clinic documents carry doctor counts', function () {
    $location = directory_clinic();
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);

    Clinic::findOrFail($location->clinic_id)->forceFill(['name' => 'Santos Medical Center'])->save();

    expect(doctor_docs($doctor)[$doctor->id.':'.$location->id]['clinic_name'])->toBe('Santos Medical Center')
        ->and($this->engine->documents('mc_test_clinics')[$location->id]['doctor_count'])->toBe(1);
});

it('adds a specialty to the documents once a reviewer confirms it', function () {
    $location = directory_clinic();
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
    $selfReported = DoctorSpecialty::where('doctor_profile_id', $doctor->id)->where('verification_status', 'SELF_REPORTED')->firstOrFail();

    $selfReported->forceFill(['verification_status' => 'CONFIRMED', 'confirmed_by' => $doctor->user_id, 'confirmed_at' => now()])->save();

    expect(doctor_docs($doctor)[$doctor->id.':online']['specialties'])->toHaveCount(2);
});

it('converges to current state whatever order or staleness the jobs arrive in (concurrency scenario 10)', function () {
    $location = directory_clinic();
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
    $first = new IndexDoctorSearchDocument($doctor->id);

    // The profile changes after the job was queued, without its own event reaching the index yet.
    DB::table('doctor_profiles')->where('id', $doctor->id)->update(['display_name' => 'Dr. Ana Santos-Cruz']);
    $second = new IndexDoctorSearchDocument($doctor->id);
    DB::table('doctor_services')->where('doctor_profile_id', $doctor->id)->whereNull('clinic_location_id')->update(['status' => 'ENDED', 'effective_until' => now()]);

    // Run them out of order and twice: each reads PostgreSQL, so the result is the same.
    $second->handle(app(DoctorDocuments::class), app(SearchIndexer::class));
    $first->handle(app(DoctorDocuments::class), app(SearchIndexer::class));
    $first->handle(app(DoctorDocuments::class), app(SearchIndexer::class));

    $docs = doctor_docs($doctor);
    expect(array_keys($docs))->toBe([$doctor->id.':'.$location->id])
        ->and($docs[$doctor->id.':'.$location->id]['display_name'])->toBe('Dr. Ana Santos-Cruz');
});

it('keeps a doctor findable by name with a profile document when no services remain', function () {
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos');
    DoctorService::where('doctor_profile_id', $doctor->id)->get()->each(fn (DoctorService $s) => $s->forceFill(['status' => 'ENDED', 'effective_until' => now()])->save());

    expect(array_keys(doctor_docs($doctor)))->toBe([$doctor->id.':profile']);
});

it('fails the job (to be retried) when the engine is down, without losing the event', function () {
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos');
    $this->engine->down = true;

    expect(fn () => (new IndexDoctorSearchDocument($doctor->id))->handle(app(DoctorDocuments::class), app(SearchIndexer::class)))->toThrow(SearchUnavailable::class);
    expect(DB::table('outbox_events')->where('aggregate_id', $doctor->id)->exists())->toBeTrue();
});

it('rebuilds a collection into a new version, swaps the alias and drops the old one', function () {
    $location = directory_clinic();
    $a = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
    $b = directory_doctor('ACTIVE', 'Dr. Ben Reyes');
    $old = $this->engine->aliasTarget('mc_test_doctors');
    // Simulate a document that no longer belongs (for example written by a bug): a rebuild clears it.
    $this->engine->upsert('mc_test_doctors', [['id' => 'ghost:online', 'doctor_id' => 'ghost', 'display_name' => 'Ghost']]);
    $this->travel(1)->seconds();

    Artisan::call('search:rebuild', ['collection' => 'all']);

    $new = $this->engine->aliasTarget('mc_test_doctors');
    expect($new)->not->toBe($old)
        ->and($this->engine->collectionExists((string) $old))->toBeFalse()
        ->and(array_column($this->engine->documents('mc_test_doctors'), 'doctor_id'))->toContain($a->id, $b->id)->not->toContain('ghost')
        ->and($this->engine->documents('mc_test_specialties'))->not->toBe([])
        ->and($this->engine->documents('mc_test_clinics'))->toHaveKey($location->id);
});

it('indexes specialties with their doctor counts', function () {
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos');
    $confirmed = DoctorSpecialty::where('doctor_profile_id', $doctor->id)->where('verification_status', 'CONFIRMED')->firstOrFail();

    $docs = app(DirectoryDocuments::class)->forSpecialty($confirmed->specialty_id);

    expect($docs[0]['kind'])->toBe('SPECIALTY')->and($docs[0]['doctor_count'])->toBe(1);
    Specialty::whereKey($confirmed->specialty_id)->update(['is_active' => false]);
    expect(app(DirectoryDocuments::class)->forSpecialty($confirmed->specialty_id))->toBe([]);
});

it('indexes, removes stale documents and searches on live Typesense', function () {
    $live = live_engine();
    if ($live === null) {
        $this->markTestSkipped('Typesense is not reachable here.');
    }
    config(['search.prefix' => 'mc_test_live_'.strtolower(Str::random(5))]);
    app()->instance(SearchEngine::class, $live);
    SearchIndexer::forgetInstalled();
    $prefix = (string) config('search.prefix');

    try {
        $location = directory_clinic();
        $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
        $found = $live->search($prefix.'_doctors', ['q' => 'Snatos', 'query_by' => 'display_name', 'filter_by' => 'doctor_id:=`'.$doctor->id.'`']);
        expect($found['found'])->toBe(2);

        // Ending the online service removes only that document (the id:!=[...] delete filter).
        DoctorService::where('doctor_profile_id', $doctor->id)->whereNull('clinic_location_id')->firstOrFail()->forceFill(['status' => 'ENDED', 'effective_until' => now()])->save();
        $after = $live->search($prefix.'_doctors', ['q' => '*', 'query_by' => 'display_name', 'filter_by' => 'doctor_id:=`'.$doctor->id.'`']);
        expect($after['found'])->toBe(1)->and($after['hits'][0]['document']['id'])->toBe($doctor->id.':'.$location->id);
    } finally {
        foreach (['doctors', 'clinics', 'specialties'] as $name) {
            if (($target = $live->aliasTarget($prefix.'_'.$name)) !== null) {
                $live->dropCollection($target);
            }
        }
    }
});
