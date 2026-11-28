<?php

namespace App\Modules\WalkIns\DTOs;

use App\Modules\WalkIns\Enums\EntryStatus;

/**
 * The doctor's walk-in queues and entries at a location for one local day, as read under lock (or for a preview, without one).
 * Ids, statuses and counts only: no names, phone numbers, triage or notes.
 *
 * @phpstan-type QueueRow array{id: string, status: string, doctor_queue: bool, at_location: bool, registered: int, waiting: int, called: int, skipped: int, in_consultation: int, guests: int}
 * @phpstan-type EntryRow array{id: string, queue_id: string, status: EntryStatus, patient_profile_id: string|null, doctor_queue: bool}
 */
final readonly class CheckOutQueueFacts
{
    /**
     * @param  list<QueueRow>  $queues  ascending id
     * @param  list<EntryRow>  $entries  ascending id
     */
    public function __construct(
        public string $doctorProfileId,
        public string $locationId,
        public string $localDate,
        public array $queues,
        public array $entries,
    ) {}

    /** CALLED or IN_CONSULTATION entries of this doctor, in any queue of the day: a check-out is refused while one exists (D-C). Doctor-wide. */
    public function calledCount(): int
    {
        return count($this->calledEntries());
    }

    /**
     * The CALLED or IN_CONSULTATION entries of this doctor that sit in a queue at the requested location only. A stale entry at another
     * clinic still blocks (calledCount), but its id is never returned here (5.8 leak fix).
     *
     * @return list<string>
     */
    public function calledEntryIds(): array
    {
        $atLocation = array_flip(array_map(fn (array $q) => $q['id'], array_filter($this->queues, fn (array $q) => $q['at_location'])));

        return array_values(array_map(fn (array $e) => $e['id'], array_filter($this->calledEntries(), fn (array $e) => isset($atLocation[$e['queue_id']]))));
    }

    /** @return list<EntryRow> */
    private function calledEntries(): array
    {
        return array_values(array_filter($this->entries, fn (array $e) => in_array($e['status'], [EntryStatus::Called, EntryStatus::InConsultation], true)));
    }

    /**
     * REGISTERED, WAITING and SKIPPED entries of the doctor's own queues: the ones a disposition decides (D-D).
     *
     * @return list<EntryRow>
     */
    public function dispositionable(): array
    {
        return array_values(array_filter($this->entries, fn (array $e) => $e['doctor_queue'] && in_array($e['status'], [EntryStatus::Registered, EntryStatus::Waiting, EntryStatus::Skipped], true)));
    }

    /** @return list<string> */
    public function dispositionableIds(): array
    {
        return array_map(fn (array $e) => $e['id'], $this->dispositionable());
    }

    /** Live guest entries that the caller must have told in person (no notification channel). */
    public function guestDispositionableCount(): int
    {
        return count(array_filter($this->dispositionable(), fn (array $e) => $e['patient_profile_id'] === null));
    }
}
