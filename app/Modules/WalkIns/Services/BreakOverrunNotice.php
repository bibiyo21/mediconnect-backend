<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\WalkIns\Contracts\BreakOverrunNotices;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BreakOverrunNotice implements BreakOverrunNotices
{
    public function __construct(
        private readonly PatientAccess $patients,
        private readonly SendsNotifications $notifications,
    ) {}

    public function notifyWaitingPatients(string $doctorProfileId, string $locationId, CarbonImmutable $now): int
    {
        $timezone = DB::table('clinic_locations')->where('id', $locationId)->value('timezone');
        $localDate = $now->setTimezone(is_string($timezone) && $timezone !== '' ? $timezone : (string) config('mediconnect.timezone'))->toDateString();

        $queueIds = WalkInQueue::query()->where('clinic_location_id', $locationId)->where('doctor_profile_id', $doctorProfileId)
            ->whereDate('local_date', $localDate)->pluck('id');

        $entries = WalkInQueueEntry::query()->whereIn('walkin_queue_id', $queueIds)
            ->whereIn('status', [EntryStatus::Waiting->value, EntryStatus::Registered->value])
            ->whereNotNull('patient_profile_id')->orderBy('id')->get(['id', 'patient_profile_id']);

        $written = 0;
        foreach ($entries as $entry) {
            foreach ($this->patients->userIds((string) $entry->patient_profile_id) as $userId) {
                $this->notifications->notify($userId, 'walkins.doctor_delayed', 'The doctor is running late', 'Your estimate has been updated.', ['entry_id' => (string) $entry->id], push: false);
                $written++;
            }
        }

        return $written;
    }
}
