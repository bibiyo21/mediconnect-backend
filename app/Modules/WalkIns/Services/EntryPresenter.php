<?php

namespace App\Modules\WalkIns\Services;

use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Presence\Contracts\PresenceAdmission;
use App\Modules\Scheduling\Contracts\WalkInDay;
use App\Modules\WalkIns\DTOs\EntryView;
use App\Modules\WalkIns\Enums\EntryStatus;
use App\Modules\WalkIns\Enums\ViewLevel;
use App\Modules\WalkIns\Estimation\WaitEstimate;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Shared\Contracts\Clock;
use Illuminate\Support\Collection;

/**
 * Builds the views a reader is allowed to see (decision 295). Names, services and estimates are loaded in batches (no query
 * per entry), and the estimate of a queue is computed once per request: it is advisory, from live data, never stored for clients (decision 98).
 * A patient (Owner) sees their own place and estimate; the staff and doctor see names; the restricted view sees none of that.
 */
final class EntryPresenter
{
    /** @var array<string, array<string, WaitEstimate>> */
    private array $estimates = [];

    public function __construct(
        private readonly QueueEstimates $queueEstimates,
        private readonly WalkInDay $day,
        private readonly PresenceAdmission $presence,
        private readonly Clock $clock,
    ) {}

    /**
     * The estimate of every entry still ahead in the queue, by entry id, in queue order. Empty for a queue that is not today's
     * (an old queue has no wait to estimate).
     *
     * @return array<string, WaitEstimate>
     */
    public function estimatesFor(WalkInQueue $queue): array
    {
        if (isset($this->estimates[$queue->id])) {
            return $this->estimates[$queue->id];
        }
        $doctorId = (string) $queue->doctor_profile_id;
        $locationId = $queue->clinic_location_id;
        $timezone = (string) ClinicLocation::query()->whereKey($locationId)->value('timezone');
        $localDate = $queue->local_date->toDateString();
        if ($localDate !== $this->clock->now()->setTimezone($timezone)->toDateString()) {
            return $this->estimates[$queue->id] = [];
        }

        $facts = $this->day->forDoctorAtLocation($doctorId, $locationId, $localDate, $queue->clinic_service_id, graceFallbackMinutes: (int) config('walkins.estimation.appointment_grace_minutes', 15));
        $entries = WalkInQueueEntry::query()->where('walkin_queue_id', $queue->id)->whereIn('status', [EntryStatus::Registered->value, EntryStatus::Waiting->value, EntryStatus::Called->value])->get();
        $inputs = $this->queueEstimates->inputs($doctorId, $localDate, $facts, $this->presence->current($doctorId, $locationId), $entries);

        return $this->estimates[$queue->id] = $this->queueEstimates->forEntries($inputs);
    }

    /**
     * @param  iterable<WalkInQueueEntry>  $entries  with their queue loaded
     * @param  int  $minutesForNext  staff views: minutes only for this many waiting entries (the board); entries beyond get position and codes
     * @return list<EntryView>
     */
    public function present(iterable $entries, ViewLevel $level, bool $withEstimate = true, bool $includePhone = false, int $minutesForNext = PHP_INT_MAX): array
    {
        $list = collect($entries)->values();
        if ($list->isEmpty()) {
            return [];
        }

        /** @var Collection<int, WalkInQueue> $queues */
        $queues = $list->map(fn (WalkInQueueEntry $e) => $e->queue)->unique('id');
        $doctors = DoctorProfile::query()->whereIn('id', $queues->pluck('doctor_profile_id')->filter()->unique())->pluck('display_name', 'id');
        $locations = ClinicLocation::query()->whereIn('id', $queues->pluck('clinic_location_id')->unique())->get(['id', 'name', 'timezone'])->keyBy('id');
        $services = DoctorService::query()->whereIn('id', $list->pluck('doctor_service_id')->unique())->get()->keyBy('id');
        $names = $level->seesNames() ? $this->patientNames($list) : collect();

        $views = [];
        $minuteSlots = [];
        foreach ($list as $entry) {
            $queue = $entry->queue;
            $estimate = $withEstimate && $level !== ViewLevel::Restricted ? ($this->estimatesFor($queue)[$entry->id] ?? null) : null;
            $service = $services->get($entry->doctor_service_id);
            // Staff minutes: the first N waiting entries of each queue, in queue order (the estimate order), then position and codes only.
            $showMinutes = $level === ViewLevel::Owner || $minutesForNext === PHP_INT_MAX;
            if (! $showMinutes && $estimate !== null && $entry->status === EntryStatus::Waiting) {
                $minuteSlots[$queue->id] = ($minuteSlots[$queue->id] ?? 0) + 1;
                $showMinutes = $minuteSlots[$queue->id] <= $minutesForNext;
            }
            $views[] = new EntryView(
                $entry, $queue, $level,
                (string) ($locations->get($queue->clinic_location_id)->timezone ?? config('mediconnect.timezone')),
                $doctors->get($queue->doctor_profile_id),
                $locations->get($queue->clinic_location_id)?->name,
                $service?->name,
                $service?->fee(),
                $estimate,
                $showMinutes,
                $level->seesNames() ? ($entry->isGuest() ? $this->guestName($entry) : ($names[$entry->patient_profile_id] ?? null)) : null,
                $includePhone && $level === ViewLevel::Staff,
            );
        }

        return $views;
    }

    /** One entry. */
    public function one(WalkInQueueEntry $entry, ViewLevel $level, bool $includePhone = false, bool $withEstimate = true, ?WaitEstimate $estimate = null): EntryView
    {
        $view = $this->present([$entry], $level, $withEstimate && $estimate === null, $includePhone)[0];
        if ($estimate === null) {
            return $view;
        }

        return new EntryView($view->entry, $view->queue, $view->level, $view->timezone, $view->doctorName, $view->locationName, $view->serviceName, $view->fee, $estimate, true, $view->displayName, $view->includePhone);
    }

    /**
     * @param  Collection<int, WalkInQueueEntry>  $entries
     * @return Collection<string, string>
     */
    private function patientNames(Collection $entries): Collection
    {
        return PatientProfile::query()->whereIn('id', $entries->pluck('patient_profile_id')->filter()->unique())->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (PatientProfile $p) => [$p->id => trim($p->first_name.' '.$p->last_name)]);
    }

    /** The guest's name, or null once a purge erased it. */
    private function guestName(WalkInQueueEntry $entry): ?string
    {
        return $entry->guest_pii_purged_at === null ? $entry->guest_name_encrypted : null;
    }
}
