<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Search analytics, zero-result queue and synonyms (database-schema.md search
 * tables, architecture.md §12.6, increment 3.4). Analytics hold no user
 * identity: a query hash, the normalized text, filter names and numbers only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE search_analytics (
                id BIGINT GENERATED ALWAYS AS IDENTITY,
                collection VARCHAR(20) NOT NULL,
                query_hash CHAR(64) NOT NULL,
                normalized_query VARCHAR(100) NOT NULL,
                filters JSONB NOT NULL DEFAULT '{}'::jsonb,
                result_count INT NOT NULL,
                latency_ms INT NOT NULL,
                cache_hit BOOLEAN NOT NULL,
                degraded BOOLEAN NOT NULL,
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (id, occurred_at),
                CONSTRAINT search_analytics_collection_check CHECK (collection IN ('doctors', 'clinics', 'specialties')),
                CONSTRAINT search_analytics_numbers_check CHECK (result_count >= 0 AND latency_ms >= 0)
            ) PARTITION BY RANGE (occurred_at)
        SQL);
        DB::statement('CREATE INDEX search_analytics_time_idx ON search_analytics (occurred_at DESC)');
        DB::statement('CREATE INDEX search_analytics_query_idx ON search_analytics (query_hash, occurred_at DESC)');
        DB::statement('CREATE TABLE search_analytics_default PARTITION OF search_analytics DEFAULT');
        DB::statement("SELECT ensure_monthly_partitions('search_analytics', 3)");

        Schema::create('search_zero_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('collection', 20);
            $table->string('normalized_query', 100);
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->string('status', 20)->default('NEW');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['collection', 'normalized_query']);
            $table->index(['status', 'occurrences']);
        });
        DB::statement("ALTER TABLE search_zero_results ADD CONSTRAINT search_zero_results_status_check CHECK (status IN ('NEW', 'SYNONYM_ADDED', 'IGNORED'))");

        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Empty = two-way (every term matches every other); set = one-way (the root also matches the synonyms).
            $table->string('root_term', 100)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE search_synonyms ADD COLUMN synonyms TEXT[] NOT NULL');
        DB::statement("ALTER TABLE search_synonyms ADD CONSTRAINT search_synonyms_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        DB::statement('ALTER TABLE search_synonyms ADD CONSTRAINT search_synonyms_size_check CHECK (cardinality(synonyms) BETWEEN 1 AND 20)');
    }

    public function down(): void
    {
        Schema::dropIfExists('search_synonyms');
        Schema::dropIfExists('search_zero_results');
        DB::statement('DROP TABLE IF EXISTS search_analytics CASCADE');
    }
};
