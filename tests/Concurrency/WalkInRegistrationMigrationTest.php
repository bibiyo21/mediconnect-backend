<?php

use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Increment 5.5a-1: the registration migration runs down and up again on a clean database, and refuses to
 * run over existing entries. It uses the owner connection and commits, so it cannot sit in RefreshDatabase's
 * transaction; the database is rebuilt around it.
 */

beforeEach(fn () => fresh_test_database());

afterEach(fn () => fresh_test_database());

/** The migration object, run on the schema owner's connection. */
function registration_migration(): object
{
    return require database_path('migrations/2026_11_23_002900_add_walkin_registration_columns.php');
}

function entry_columns(): array
{
    return Schema::connection('pgsql_owner')->getColumnListing('walkin_queue_entries');
}

function named(string $name): bool
{
    return DB::connection('pgsql_owner')->selectOne('SELECT 1 AS found FROM pg_constraint WHERE conname = ? UNION ALL SELECT 1 FROM pg_indexes WHERE indexname = ? LIMIT 1', [$name, $name]) !== null;
}

$added = ['doctor_service_id', 'triage_reason_code', 'triage_note_encrypted', 'privacy_notice_version', 'privacy_notice_shown_by_user_id', 'privacy_notice_shown_at', 'hmo_card_name_encrypted'];
$names = ['walkin_queue_entries_triage_check', 'walkin_queue_entries_privacy_notice_check', 'walkin_queue_entries_hmo_text_check', 'walkin_queue_entries_channel_check', 'walkin_queue_entries_privacy_notice_shown_by_user_id_index', 'uq_entry_registrant_idempotency', 'ix_entry_remote_registrant', 'uq_walkin_queue_prefix'];

it('rolls back to the 5.1 shape and migrates forward again on a fresh database', function () use ($added, $names) {
    DB::usingConnection('pgsql_owner', function () use ($added, $names) {
        expect(array_intersect($added, entry_columns()))->toHaveCount(count($added))
            ->and(DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgname = 'walkin_queue_entries_match_service'")->n)->toBe(1);
        foreach ($names as $name) {
            expect(named($name))->toBeTrue($name);
        }

        registration_migration()->down();

        expect(array_intersect($added, entry_columns()))->toBe([])
            ->and(DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgname = 'walkin_queue_entries_match_service'")->n)->toBe(0)
            ->and(DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'walkin_queue_entries_match_service'")->n)->toBe(0);
        foreach ($names as $name) {
            expect(named($name))->toBeFalse($name);
        }
        // The 5.1 checks are back: three channels, priority 0 to 9, no arrival needed for WAITING, no CAPACITY_OVERRIDDEN.
        $defs = fn (string $n) => (string) DB::selectOne('SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?', [$n])->def;
        expect($defs('walkin_queue_entries_values_check'))->not->toContain('DOCTOR_APP')->toContain('triage_priority >= 0')
            ->and($defs('walkin_queue_entries_coherence_check'))->not->toContain('arrived_at')
            ->and($defs('walkin_queue_events_values_check'))->not->toContain('CAPACITY_OVERRIDDEN')
            ->and($defs('walkin_queue_entries_identity_check'))->not->toContain('hmo_card_name_encrypted');

        registration_migration()->up();

        expect(array_intersect($added, entry_columns()))->toHaveCount(count($added))
            ->and(DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgname = 'walkin_queue_entries_match_service'")->n)->toBe(1)
            ->and($defs('walkin_queue_entries_values_check'))->toContain('DOCTOR_APP')
            ->and($defs('walkin_queue_entries_coherence_check'))->toContain('arrived_at')
            ->and($defs('walkin_queue_events_values_check'))->toContain('CAPACITY_OVERRIDDEN')
            ->and($defs('walkin_queue_entries_identity_check'))->toContain('hmo_card_name_encrypted')->toContain('triage_note_encrypted');
        foreach ($names as $name) {
            expect(named($name))->toBeTrue($name);
        }
    });
});

/** A location, a doctor, an in-person service and a queue, committed (this suite has no wrapping transaction). */
function registration_fixture(): array
{
    seed_roles();
    test()->seed(SpecialtySeeder::class);
    $location = directory_clinic();
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
    $service = (string) DB::table('doctor_services')->where('doctor_profile_id', $doctor->id)->where('consultation_type', 'IN_PERSON')->value('id');
    $queue = (string) Str::uuid7();
    DB::table('walkin_queues')->insert(['id' => $queue, 'clinic_location_id' => $location->id, 'doctor_profile_id' => $doctor->id, 'local_date' => '2026-12-01']);

    return [$doctor, $service, $queue];
}

it('refuses to add the NOT NULL doctor service over existing entries', function () {
    [$doctor, , $queue] = registration_fixture();

    DB::usingConnection('pgsql_owner', function () use ($doctor, $queue) {
        registration_migration()->down();
        // The 5.1 shape: an entry with no doctor service.
        DB::table('walkin_queue_entries')->insert(['id' => (string) Str::uuid7(), 'walkin_queue_id' => $queue, 'queue_number' => 1, 'guest_name_encrypted' => 'x',
            'registered_by_user_id' => $doctor->user_id, 'registration_channel' => 'STAFF_CONSOLE', 'status' => 'WAITING', 'payer_type' => 'SELF_PAY', 'queue_sort_at' => now()]);

        expect(fn () => registration_migration()->up())->toThrow(RuntimeException::class, 'has rows');
    });
});

it('refuses to roll back once entries or capacity-override events exist, and says why', function () {
    [$doctor, $service, $queue] = registration_fixture();
    $entry = (string) Str::uuid7();
    DB::table('walkin_queue_entries')->insert(['id' => $entry, 'walkin_queue_id' => $queue, 'queue_number' => 1, 'guest_name_encrypted' => 'x', 'doctor_service_id' => $service,
        'registered_by_user_id' => $doctor->user_id, 'registration_channel' => 'DOCTOR_APP', 'status' => 'REGISTERED', 'payer_type' => 'SELF_PAY',
        'privacy_notice_version' => '2026-11', 'privacy_notice_shown_by_user_id' => $doctor->user_id, 'privacy_notice_shown_at' => now()]);
    DB::table('walkin_queue_events')->insert(['walkin_queue_id' => $queue, 'walkin_queue_entry_id' => $entry, 'event_type' => 'CAPACITY_OVERRIDDEN', 'actor_id' => $doctor->user_id]);

    DB::usingConnection('pgsql_owner', function () {
        expect(fn () => registration_migration()->down())->toThrow(RuntimeException::class, 'irreversible once it holds data');
        // Nothing was changed: the columns and the new values are still there.
        expect(entry_columns())->toContain('doctor_service_id', 'hmo_card_name_encrypted');
    });
});
