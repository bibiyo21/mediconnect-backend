<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The platform scheduling policy (increment 4.2). Hold values are D-10
 * (approved 2026-11-21); the 60-day horizon is Phase 4 plan Q6. Booking
 * notice, advance limit and same-day are starting values for product review.
 * D-22 values (cancellation, refunds, late grace) are not decided and stay
 * null. Idempotent: only inserts when no platform policy exists.
 */
final class SchedulingPolicySeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('scheduling_policies')->where('scope_type', 'PLATFORM')->exists()) {
            return;
        }
        DB::table('scheduling_policies')->insert([
            'id' => (string) Str::uuid7(), 'scope_type' => 'PLATFORM', 'scope_id' => null,
            'min_booking_notice_minutes' => 60, 'max_advance_booking_days' => 60, 'allow_same_day' => true,
            'reservation_hold_minutes' => 10, 'reservation_max_extension_minutes' => 10, 'confirmation_hold_hours' => 24,
            'availability_horizon_days' => 60, 'effective_from' => '2026-01-01 00:00:00+00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
