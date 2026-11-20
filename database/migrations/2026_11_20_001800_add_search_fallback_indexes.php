<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trigram indexes for the PostgreSQL search fallback (architecture.md §12.6):
 * prefix and similarity matching on names stays indexed, never an unrestricted
 * LIKE scan (§32.5). f_unaccent is the IMMUTABLE wrapper from Phase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS doctor_profiles_name_trgm ON doctor_profiles USING gin (f_unaccent(lower(display_name)) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS specialties_name_trgm ON specialties USING gin (f_unaccent(lower(name)) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS clinics_name_trgm ON clinics USING gin (f_unaccent(lower(name)) gin_trgm_ops)');
        DB::statement("CREATE INDEX IF NOT EXISTS doctor_profiles_active_idx ON doctor_profiles (profile_completeness DESC, display_name) WHERE onboarding_status = 'ACTIVE'");
    }

    public function down(): void
    {
        foreach (['doctor_profiles_name_trgm', 'specialties_name_trgm', 'clinics_name_trgm', 'doctor_profiles_active_idx'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
