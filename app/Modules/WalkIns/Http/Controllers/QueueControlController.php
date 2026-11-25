<?php

namespace App\Modules\WalkIns\Http\Controllers;

use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Http\Requests\EntryActionRequest;
use App\Modules\WalkIns\Http\Resources\QueueResource;
use App\Modules\WalkIns\Models\WalkInQueue;
use App\Modules\WalkIns\Services\EntryActions;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Pause and reopen a walk-in queue (increment 5.5a). Closing, with the disposition of each waiting entry, arrives in 5.8. */
final class QueueControlController
{
    public function __construct(private readonly EntryActions $actions) {}

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
}
