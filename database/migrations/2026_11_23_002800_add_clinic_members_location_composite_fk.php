<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L-7 (increment 5.4b): a clinic member restricted to a location must be restricted to a location of
 * the SAME clinic. The single-column foreign key only proves the location exists; the composite key
 * (clinic_id, clinic_location_id) -> clinic_locations (clinic_id, id) proves it belongs to the clinic.
 * A clinic-wide membership (NULL location) is not checked (MATCH SIMPLE), as before.
 *
 * Existing rows are never rewritten: if any membership already points at another clinic's location
 * the migration stops and lists the offending ids, so a person decides what the right clinic is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $bad = DB::select(<<<'SQL'
            SELECT m.id FROM clinic_members m
            JOIN clinic_locations l ON l.id = m.clinic_location_id
            WHERE m.clinic_location_id IS NOT NULL AND l.clinic_id <> m.clinic_id
            ORDER BY m.id
            SQL);
        if ($bad !== []) {
            $ids = implode(', ', array_map(fn ($row) => $row->id, $bad));

            throw new RuntimeException("clinic_members rows point at a location of another clinic; fix them before migrating: {$ids}");
        }

        DB::statement('ALTER TABLE clinic_locations ADD CONSTRAINT clinic_locations_clinic_id_id_unique UNIQUE (clinic_id, id)');
        DB::statement('ALTER TABLE clinic_members ADD CONSTRAINT clinic_members_location_in_clinic_fk FOREIGN KEY (clinic_id, clinic_location_id) REFERENCES clinic_locations (clinic_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clinic_members DROP CONSTRAINT IF EXISTS clinic_members_location_in_clinic_fk');
        DB::statement('ALTER TABLE clinic_locations DROP CONSTRAINT IF EXISTS clinic_locations_clinic_id_id_unique');
    }
};
