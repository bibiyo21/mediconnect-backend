<?php

namespace App\Modules\WalkIns\Http\Controllers;

use App\Modules\Users\Models\User;
use App\Modules\WalkIns\DTOs\CloseDisposition;
use App\Modules\WalkIns\Enums\CloseAction;
use App\Modules\WalkIns\Enums\ViewLevel;
use App\Modules\WalkIns\Http\Requests\CloseQueueRequest;
use App\Modules\WalkIns\Http\Requests\EntryActionRequest;
use App\Modules\WalkIns\Http\Requests\TransferEntryRequest;
use App\Modules\WalkIns\Http\Resources\QueueResource;
use App\Modules\WalkIns\Http\Resources\WalkInEntryResource;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Services\EntryActions;
use App\Modules\WalkIns\Services\EntryPresenter;
use App\Modules\WalkIns\Services\QueueClose;
use App\Modules\WalkIns\Services\QueueTransfer;
use App\Modules\WalkIns\Services\WalkInAccess;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Queue control of walk-ins: pause and reopen (increment 5.5a), close (increment 5.8-2, D-F) and the transfer of one entry to another queue
 * (5.8-2, D-G). Every move locks the queue row (and the entry) and checks the state under the lock.
 */
final class QueueControlController
{
    public function __construct(
        private readonly EntryActions $actions,
        private readonly QueueClose $close,
        private readonly QueueTransfer $transfer,
        private readonly EntryPresenter $presenter,
        private readonly WalkInAccess $access,
    ) {}

    /** Pause a queue: no new registrations (QUEUE_CLOSED, reason_code `paused`); entries already in it are untouched. OPEN only (409 otherwise). Needs `walkins.manage_queue` at the location. */
    public function pause(EntryActionRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(new QueueResource($this->actions->pause($user, WalkInQueue::query()->findOrFail($id))));
    }

    /** Reopen a paused queue. PAUSED only (409 otherwise; a closed queue stays closed). Needs `walkins.manage_queue` at the location. */
    public function open(EntryActionRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(new QueueResource($this->actions->open($user, WalkInQueue::query()->findOrFail($id))));
    }

    /**
     * Close a queue for the day.
     *
     * Needs `walkins.manage_queue` at the location (staff, or the queue's own doctor). Body `dispositions`: one item per live entry (REGISTERED,
     * WAITING or SKIPPED), each `{entry_id, action}` with action CANCEL_WITH_NOTICE (a patient is told in the app) or TOLD_IN_PERSON (staff told
     * them; a guest must use this). A CALLED or IN_CONSULTATION entry blocks the close. The queue is CLOSED for good: no walk-ins that day.
     * `409` codes: INVALID_STATE_TRANSITION (reason_code doctor_checked_in: the doctor is checked in here; or errors.status: the queue is already CLOSED),
     * ACTIVE_CONSULTATION (reason_code called or in_progress, errors.entry_id), CONCURRENT_MODIFICATION (retry with a NEW key). `422` lists the
     * entry ids that lack a disposition, or are not live, under errors.dispositions.
     */
    public function close(CloseQueueRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $queue = WalkInQueue::query()->findOrFail($id);
        $dispositions = array_values(array_map(
            fn (array $row) => new CloseDisposition((string) $row['entry_id'], CloseAction::from((string) $row['action'])),
            (array) $request->validated('dispositions'),
        ));

        return ApiResponse::success(new QueueResource($this->close->close($user, $queue, $dispositions)));
    }

    /**
     * Transfer one live entry to another queue at the same location and day.
     *
     * Staff only (`walkins.manage_queue` at the location; a doctor may not move a patient between queues). The entry keeps its id, status and
     * arrival order and takes the next number of the target queue. The target must be OPEN, its doctor must be checked in or not yet in (not out),
     * and the patient must not already hold a place there. `409` codes: INVALID_STATE_TRANSITION (the entry is not REGISTERED or WAITING; errors.status),
     * DOCTOR_NOT_AVAILABLE (reason_code checked_out), QUEUE_CLOSED (reason_code paused, closed or cutoff_passed), ENTRY_ALREADY_LIVE (errors.entry_id),
     * CAPACITY_OVERRIDE_REQUIRED (send over_capacity_ack), CONCURRENT_MODIFICATION (retry with a NEW key). `404` for a target the caller cannot see.
     */
    public function transfer(TransferEntryRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);
        $done = $this->transfer->transfer($user, $entry, (string) $request->validated('walkin_queue_id'), $request->boolean('over_capacity_ack'));
        $done->load('queue');
        $queue = $done->queue;
        $level = $this->access->viewLevel($user, $queue->clinic_location_id, $queue->doctor_profile_id);

        return ApiResponse::success(new WalkInEntryResource($this->presenter->one($done, $level === ViewLevel::None || ! $level->seesNames() ? ViewLevel::Owner : $level)));
    }
}
