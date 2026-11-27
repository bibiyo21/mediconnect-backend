<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Increment 5.6-1: the queue_sort_at migration runs down and up again on a clean database. It uses the owner connection and
 * commits, so it cannot sit in RefreshDatabase's transaction; the database is rebuilt around it.
 */

beforeEach(fn () => fresh_test_database());

afterEach(fn () => fresh_test_database());

function queue_ops_migration(): object
{
    return require database_path('migrations/2026_11_27_003100_add_queue_sort_at_and_held_entry_index.php');
}

function db_object_exists(string $name): bool
{
    return DB::connection('pgsql_owner')->selectOne('SELECT 1 AS found FROM pg_constraint WHERE conname = ? UNION ALL SELECT 1 FROM pg_indexes WHERE indexname = ? LIMIT 1', [$name, $name]) !== null;
}

it('rolls back to the previous index shape and migrates forward again', function () {
    DB::usingConnection('pgsql_owner', function () {
        $index = fn () => (string) DB::selectOne("SELECT indexdef FROM pg_indexes WHERE indexname = 'ix_queue_next'")->indexdef;
        expect(Schema::getColumnListing('walkin_queue_entries'))->toContain('queue_sort_at')
            ->and(db_object_exists('walkin_queue_entries_sort_check'))->toBeTrue()
            ->and(db_object_exists('uq_one_held_entry_per_patient'))->toBeTrue()
            ->and($index())->toContain('queue_sort_at');

        queue_ops_migration()->down();

        expect(Schema::getColumnListing('walkin_queue_entries'))->not->toContain('queue_sort_at')
            ->and(db_object_exists('walkin_queue_entries_sort_check'))->toBeFalse()
            ->and(db_object_exists('uq_one_held_entry_per_patient'))->toBeFalse()
            ->and($index())->toContain('status, triage_priority DESC, queue_number')
            // The 5.1 live index stays through both directions.
            ->and(db_object_exists('uq_one_live_entry_per_patient'))->toBeTrue();

        queue_ops_migration()->up();

        expect(Schema::getColumnListing('walkin_queue_entries'))->toContain('queue_sort_at')
            ->and(db_object_exists('walkin_queue_entries_sort_check'))->toBeTrue()
            ->and(db_object_exists('uq_one_held_entry_per_patient'))->toBeTrue()
            ->and($index())->toContain('queue_sort_at')
            ->and(db_object_exists('uq_one_live_entry_per_patient'))->toBeTrue();
    });
});

it('refuses to roll back over a returned entry and rolls back over normal arrivals', function () {
    DB::usingConnection('pgsql_owner', function () {
        $fx = registration_fixture();
        [$doctor, $service, $queue] = $fx;
        $row = fn (int $n, ?string $sort) => DB::table('walkin_queue_entries')->insert(['id' => (string) Str::uuid7(), 'walkin_queue_id' => $queue, 'queue_number' => $n,
            'guest_name_encrypted' => 'x', 'registered_by_user_id' => $doctor->user_id, 'registration_channel' => 'STAFF_CONSOLE', 'status' => 'WAITING', 'payer_type' => 'SELF_PAY',
            'doctor_service_id' => $service, 'arrived_at' => '2026-12-01 01:00:00+00', 'queue_sort_at' => $sort, 'privacy_notice_version' => 'v1', 'privacy_notice_shown_by_user_id' => $doctor->user_id, 'privacy_notice_shown_at' => now()]);

        $row(1, '2026-12-01 01:00:00+00');
        $row(2, '2026-12-01 02:00:00+00'); // returned: its sort key moved
        expect(fn () => queue_ops_migration()->down())->toThrow(RuntimeException::class, 'Refusing');

        DB::table('walkin_queue_entries')->where('queue_number', 2)->update(['queue_sort_at' => '2026-12-01 01:00:00+00']);
        queue_ops_migration()->down();
        expect(Schema::getColumnListing('walkin_queue_entries'))->not->toContain('queue_sort_at');
        queue_ops_migration()->up();
    });
});
