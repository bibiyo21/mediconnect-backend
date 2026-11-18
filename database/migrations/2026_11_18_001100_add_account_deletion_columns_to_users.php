<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account deletion requests (D-38): a request starts a grace period; signing
 * in cancels it; afterwards the account is anonymised (database-schema.md §18).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('deletion_requested_at')->nullable();
            $table->timestampTz('anonymized_at')->nullable();
        });

        DB::statement('CREATE INDEX users_pending_deletion ON users (deletion_requested_at) WHERE deletion_requested_at IS NOT NULL AND anonymized_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_pending_deletion');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['deletion_requested_at', 'anonymized_at']);
        });
    }
};
