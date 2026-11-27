<?php

use App\Modules\Consultations\Contracts\CareRelationships;
use App\Modules\Consultations\DTOs\WalkInCareGrant;
use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\WalkIns\Services\ConsultationEntries;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Grants a walk-in's care relationship the way a consultation start does, from the entry's own facts (the test stands in for the start).
 * Returns the care relationship id, or null for a guest.
 */
function care_grant(string $entryId, CarbonImmutable $at, string $timezone = 'Asia/Manila'): ?string
{
    $row = DB::table('walkin_queue_entries as e')->join('walkin_queues as q', 'q.id', '=', 'e.walkin_queue_id')
        ->where('e.id', $entryId)->first(['e.patient_profile_id', 'e.assigned_doctor_profile_id', 'q.doctor_profile_id']);
    $doctor = (string) ($row->assigned_doctor_profile_id ?? $row->doctor_profile_id);

    return app(CareRelationships::class)->grantForWalkIn(new WalkInCareGrant($entryId, $doctor, $row->patient_profile_id, $timezone, $at));
}

/**
 * A POST as the given session, with a new Idempotency-Key. The auth guard caches the user of the last request, so it is cleared first: without
 * that, a doctor's request made straight after a staff request would authenticate as the staff member.
 *
 * @param  array<string, mixed>  $body
 */
function act_post(string $url, array $headers, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson($url, $body, [...$headers, 'Idempotency-Key' => (string) Str::uuid7()]);
}

/** Clears the auth guard before a request made through another helper (walk-in and presence calls). */
function fresh_session(): void
{
    app('auth')->forgetGuards();
}

/** Gives the consultation start and end the same pinned clock as the walk-in fixtures (their day check reads it). */
function consultation_clock($clock): void
{
    app()->when([ConsultationLifecycle::class, ConsultationEntries::class])
        ->needs(Clock::class)->give(fn () => $clock);
}
