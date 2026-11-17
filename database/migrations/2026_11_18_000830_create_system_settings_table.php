<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administration module: runtime settings an administrator can change
 * (`settings.manage`). Never secrets: those stay in the environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->jsonb('value');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
