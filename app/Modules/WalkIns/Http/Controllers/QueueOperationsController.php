<?php

namespace App\Modules\WalkIns\Http\Controllers;

use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Enums\RegistrationChannel;
use App\Modules\WalkIns\Enums\ViewLevel;
use App\Modules\WalkIns\Http\Requests\CallNextRequest;
use App\Modules\WalkIns\Http\Requests\CallWalkInRequest;
use App\Modules\WalkIns\Http\Requests\ChangePriorityRequest;
use App\Modules\WalkIns\Http\Requests\EntryActionRequest;
use App\Modules\WalkIns\Http\Resources\WalkInEntryResource;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Services\EntryPresenter;
use App\Modules\WalkIns\Services\QueueOperations;
use App\Modules\WalkIns\Services\WalkInAccess;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Queue operations of increment 5.6-2: call-next, call, recall, skip, no-show, return and priority. Every move needs an `Idempotency-Key`
 * and is guarded by the queue and entry locks and by database constraints. A call is a place in line turning into a visit: it never charges
 * anything and never says the patient is covered. Call-next replays 4xx answers for 48 hours, so a client must send a NEW key for each attempt.
 *
 * Refusal shape: 404 when the caller has no standing at the queue, 403 when they can read it but not change it, otherwise a 409 code.
 */
final class QueueOperationsController
{
    public function __construct(
        private readonly QueueOperations $operations,
        private readonly EntryPresenter $presenter,
        private readonly WalkInAccess $access,
    ) {}

    /**
     * Call the next patient.
     *
     * Calls the head of the queue order (triage, then the time they became ready, then the number); REGISTERED entries are never called. Needs
     * `walkins.call_patient`. `409` codes: DOCTOR_NOT_AVAILABLE (reason_code on_break, unavailable, not_checked_in, checked_out), QUEUE_CLOSED (reason_code),
     * ENTRY_ALREADY_CALLED (errors.entry_id), DOCTOR_ALREADY_CALLING, QUEUE_EMPTY, APPOINTMENT_WINDOW_PROTECTED (reason_code appointment or hold,
     * errors.blocked_until), CONCURRENT_MODIFICATION (retry with a new key). Never overrides a protected window, and `override_reason_code` in the body is refused (422).
     */
    public function callNext(CallNextRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $queue = WalkInQueue::query()->findOrFail($id);

        return $this->respond($user, $this->operations->callNext($user, $queue), $queue);
    }

    /**
     * Call one patient.
     *
     * The entry must be WAITING and the head of the queue order (409 NOT_NEXT_IN_ORDER otherwise). `override_reason_code` lets a triaged patient pass an
     * appointment-window refusal and needs `walkins.manage_queue` (422 for a patient with no triage).
     */
    public function call(CallWalkInRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);

        return $this->respond($user, $this->operations->call($user, $entry, $request->overrideReason()), $entry->queue);
    }

    /** Call the called patient again (CALLED only). Counts up to the doctor's recall limit at this location (409 RECALL_LIMIT_REACHED); nothing changes by itself afterwards. Needs `walkins.call_patient`. */
    public function recall(EntryActionRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);

        return $this->respond($user, $this->operations->recall($user, $entry), $entry->queue);
    }

    /** Skip the called patient who did not come (CALLED to SKIPPED). They keep their place in the day and can be returned. Needs `walkins.manage_queue`. */
    public function skip(EntryActionRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);

        return $this->respond($user, $this->operations->skip($user, $entry), $entry->queue);
    }

    /** Mark a no-show: a skipped patient at any time, a called patient only after the recall limit is reached. Needs `walkins.manage_queue`. */
    public function noShow(EntryActionRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);

        return $this->respond($user, $this->operations->noShow($user, $entry), $entry->queue);
    }

    /**
     * Put a skipped patient back in line (SKIPPED to WAITING), at the end of their priority group from now.
     *
     * Staff or doctor only (`walkins.manage_queue`). The queue must be open or paused and belong to today (409 QUEUE_CLOSED with reason_code `closed` or `not_today`).
     */
    public function returnToQueue(EntryActionRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);

        return $this->respond($user, $this->operations->returnToQueue($user, $entry), $entry->queue);
    }

    /** Change the triage level of a WAITING or SKIPPED entry. Needs `walkins.manage_queue`; patients cannot. The reason is a code; the event and audit row carry codes only. */
    public function priority(ChangePriorityRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);

        return $this->respond($user, $this->operations->setPriority($user, $entry, $request->level(), $request->reason(), $request->note()), $entry->queue);
    }

    /** The view follows the channel the caller acted through (staff console or doctor app), never wider; anything else gets the restricted view. */
    private function respond(User $user, WalkInQueueEntry $done, WalkInQueue $queue): JsonResponse
    {
        $channel = $this->access->actingChannel($user, 'walkins.manage_queue', $queue->clinic_location_id, $queue->doctor_profile_id)
            ?? $this->access->actingChannel($user, 'walkins.call_patient', $queue->clinic_location_id, $queue->doctor_profile_id);
        $level = match ($channel) {
            RegistrationChannel::StaffConsole => ViewLevel::Staff,
            RegistrationChannel::DoctorApp => ViewLevel::Doctor,
            default => ViewLevel::Restricted,
        };

        return ApiResponse::success(new WalkInEntryResource($this->presenter->one($done->setRelation('queue', $queue), $level)));
    }
}
