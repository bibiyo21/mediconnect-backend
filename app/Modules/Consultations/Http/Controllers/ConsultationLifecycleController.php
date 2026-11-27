<?php

namespace App\Modules\Consultations\Http\Controllers;

use App\Modules\Consultations\Http\Requests\ConsultationActionRequest;
use App\Modules\Consultations\Http\Resources\ConsultationResource;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Consultation start, end and the walk-in complete (increment 5.7, api-specification.md §2.10). Every write needs an `Idempotency-Key`.
 * A retry of a start or an end that already happened answers 200 with the current consultation and writes nothing.
 *
 * Refusal shape: 404 when the caller has no standing at the consultation; 403 when they can see it but may not change it; otherwise a 409
 * (DOCTOR_NOT_AVAILABLE, QUEUE_CLOSED, INVALID_STATE_TRANSITION, ACTIVE_CONSULTATION, CONCURRENT_MODIFICATION).
 */
final class ConsultationLifecycleController
{
    public function __construct(private readonly ConsultationLifecycle $lifecycle) {}

    /**
     * Start a walk-in consultation.
     *
     * Only the consultation's own doctor may start it (403 otherwise; 404 for anyone with no standing). The doctor must be checked in at this
     * location (IN, not on break or unavailable). The entry must be CALLED, the queue today's, and the assignment active. Starting grants the
     * patient's care relationship to the doctor, for the rest of the visit's local day plus the configured days. A guest gets no grant.
     * Errors: 409 DOCTOR_NOT_AVAILABLE (reason_code checked_out, on_break, unavailable, not_checked_in, no_assignment, doctor_not_active or
     * location_not_active), QUEUE_CLOSED (reason_code closed or not_today), INVALID_STATE_TRANSITION (errors.status), ACTIVE_CONSULTATION
     * (another consultation of this doctor is running), CONCURRENT_MODIFICATION (retry with a NEW key). A doctor's own patient profile is
     * refused with 403.
     */
    public function start(ConsultationActionRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $consultation = Consultation::query()->findOrFail($id);
        $this->authorise($user, $consultation, 'start');

        return ApiResponse::success(new ConsultationResource($this->lifecycle->start($user, $consultation)));
    }

    /**
     * End a walk-in consultation.
     *
     * Only the consultation's own doctor may end it. Ending never depends on presence: the doctor may end while on break or unavailable. The
     * duration is recorded in whole seconds and the care relationship is kept until its access window closes.
     * Errors: 409 INVALID_STATE_TRANSITION (errors.status), CONCURRENT_MODIFICATION (retry with a NEW key).
     */
    public function end(ConsultationActionRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $consultation = Consultation::query()->findOrFail($id);
        $this->authorise($user, $consultation, 'end');

        return ApiResponse::success(new ConsultationResource($this->lifecycle->end($user, $consultation)));
    }

    /**
     * Complete a walk-in: end the consultation of this queue entry.
     *
     * The same rules as end. `id` is the walk-in entry (not the consultation), so the client does not need the consultation id.
     * Errors: 404 when no consultation exists for the entry or the caller has no standing; 403 for a caller who may see it but not end it.
     */
    public function complete(ConsultationActionRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $consultation = Consultation::query()->where('walkin_queue_entry_id', $id)->firstOrFail();
        $this->authorise($user, $consultation, 'end');

        return ApiResponse::success(new ConsultationResource($this->lifecycle->end($user, $consultation)));
    }

    /** No standing is a 404; standing without the write permission is a 403. */
    private function authorise(User $user, Consultation $consultation, string $ability): void
    {
        abort_unless($user->can('view', $consultation), 404, 'Not found.');
        abort_unless($user->can($ability, $consultation), 403, 'You do not have access to change this consultation.');
    }
}
