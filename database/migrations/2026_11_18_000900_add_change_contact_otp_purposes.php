<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Increment 2.1: codes that prove a NEW email address or mobile number before
 * it replaces (or is added to) the account's contact details.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE otp_challenges DROP CONSTRAINT otp_challenges_purpose_check');
        DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT otp_challenges_purpose_check CHECK (purpose IN ('REGISTER', 'VERIFY_EMAIL', 'VERIFY_MOBILE', 'RESET_PASSWORD', 'CHANGE_EMAIL', 'CHANGE_MOBILE'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM otp_challenges WHERE purpose IN ('CHANGE_EMAIL', 'CHANGE_MOBILE')");
        DB::statement('ALTER TABLE otp_challenges DROP CONSTRAINT otp_challenges_purpose_check');
        DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT otp_challenges_purpose_check CHECK (purpose IN ('REGISTER', 'VERIFY_EMAIL', 'VERIFY_MOBILE', 'RESET_PASSWORD'))");
    }
};
