<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\WalkIns\Contracts\WalkInConsultationEntries;
use App\Modules\WalkIns\DTOs\WalkInEntryFacts;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\QueueStatus;
use App\Modules\WalkIns\Exceptions\InvalidEntryTransition;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Walk-in entry moves made by a consultation (increment 5.7). The queue row is locked before the entry, as on every queue path.
 * The state checks that belong to the consultation are made by Consultations; the queue-day and assignment facts are reported here.
 */
final class ConsultationEntries implements WalkInConsultationEntries
{
    public function __construct(
        private readonly QueueRealtime $realtime,
        private readonly Clock $clock,
    ) {}

    public function lockEntry(string $entryId): WalkInEntryFacts
    {
        $queueId = (string) WalkInQueueEntry::query()->whereKey($entryId)->value('walkin_queue_id');
        $queue = WalkInQueue::query()->whereKey($queueId)->lockForUpdate()->firstOrFail();
        $entry = WalkInQueueEntry::query()->whereKey($entryId)->lockForUpdate()->firstOrFail();

        $doctorId = $entry->assigned_doctor_profile_id ?? $queue->doctor_profile_id ?? '';
        $timezone = (string) ClinicLocation::query()->whereKey($queue->clinic_location_id)->value('timezone');
        $timezone = $timezone !== '' ? $timezone : (string) config('mediconnect.timezone');
        $today = $this->clock->now()->setTimezone($timezone)->toDateString();
        $doctorActive = $doctorId !== '' && DB::table('doctor_profiles')->where('id', $doctorId)->value('onboarding_status') === 'ACTIVE';
        $location = DB::table('clinic_locations as l')->join('clinics as c', 'c.id', '=', 'l.clinic_id')
            ->where('l.id', $queue->clinic_location_id)->first(['l.status as location_status', 'c.status as clinic_status']);
        $locationActive = $location !== null && $location->location_status === 'ACTIVE' && $location->clinic_status === 'ACTIVE';

        return new WalkInEntryFacts(
            entryId: $entry->id,
            queueId: $queue->id,
            doctorProfileId: $doctorId,
            patientProfileId: $entry->patient_profile_id,
            locationId: $queue->clinic_location_id,
            timezone: $timezone,
            localDate: $queue->local_date->toDateString(),
            status: $entry->status,
            queueClosed: $queue->status === QueueStatus::Closed,
            queueIsToday: $queue->local_date->toDateString() === $today,
            assignmentActive: $doctorId !== '' && DoctorClinicAssignment::activeAt($doctorId, $queue->clinic_location_id),
            doctorActive: $doctorActive,
            locationActive: $locationActive,
        );
    }

    public function markInConsultation(string $entryId, string $actorUserId, CarbonImmutable $at): void
    {
        $this->move($entryId, $actorUserId, $at, EntryStatus::InConsultation, 'STARTED');
    }

    public function markCompleted(string $entryId, string $actorUserId, CarbonImmutable $at): void
    {
        $this->move($entryId, $actorUserId, $at, EntryStatus::Completed, 'COMPLETED');
    }

    /** The entry is already locked by lockEntry; the move follows the entry table and writes its event. Realtime hint after commit. */
    private function move(string $entryId, string $actorUserId, CarbonImmutable $at, EntryStatus $to, string $eventType): void
    {
        $entry = WalkInQueueEntry::query()->whereKey($entryId)->firstOrFail();
        $queue = WalkInQueue::query()->whereKey($entry->walkin_queue_id)->firstOrFail();
        $from = $entry->status;
        if (! EntryTransitions::allows($from, $to)) {
            throw InvalidEntryTransition::entry($from, strtolower($eventType));
        }

        $attributes = ['status' => $to];
        if ($to === EntryStatus::InConsultation) {
            $attributes['consultation_started_at'] = $at;
        } else {
            $attributes['completed_at'] = $at;
        }
        $entry->forceFill($attributes)->save();

        DB::table('walkin_queue_events')->insert([
            'walkin_queue_id' => $queue->id, 'walkin_queue_entry_id' => $entry->id, 'event_type' => $eventType,
            'from_status' => $from->value, 'to_status' => $to->value, 'actor_id' => $actorUserId, 'reason' => null, 'metadata' => null,
        ]);

        $this->realtime->entryChanged($queue, $entry);
    }
}
