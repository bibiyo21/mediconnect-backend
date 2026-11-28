<?php

namespace App\Modules\Presence\Http\Controllers;

use App\Modules\Presence\Enums\PresenceChangeMethod;
use App\Modules\Presence\Http\Requests\CheckInRequest;
use App\Modules\Presence\Http\Requests\CheckOutPreviewRequest;
use App\Modules\Presence\Http\Requests\CheckOutRequest;
use App\Modules\Presence\Http\Requests\MarkUnavailableRequest;
use App\Modules\Presence\Http\Requests\PresenceActionRequest;
use App\Modules\Presence\Http\Requests\StartBreakRequest;
use App\Modules\Presence\Http\Resources\CheckOutPreviewResource;
use App\Modules\Presence\Http\Resources\CheckOutResultResource;
use App\Modules\Presence\Http\Resources\PresenceStateResource;
use App\Modules\Presence\Http\SourceDevice;
use App\Modules\Presence\Models\DoctorPresenceSession;
use App\Modules\Presence\Services\PresenceAccess;
use App\Modules\Presence\Services\PresenceActor;
use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\Presence\Services\PresenceReader;
use App\Modules\Presence\Services\PresenceSessions;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Doctor presence at a clinic location (increment 5.3, api-specification.md §2.7). The doctor
 * acts for themself (`presence.check_in`), or staff act for a doctor (`presence.manage` at the
 * location). Writes are guarded by state, not by an Idempotency-Key: a repeat of the same target
 * state answers 200 with the current state and writes nothing; any other illegal move answers 409
 * INVALID_STATE_TRANSITION (`reason_code`, and the current status where the caller may know it).
 */
final class PresenceController
{
    public function __construct(
        private readonly PresenceSessions $sessions,
        private readonly PresenceAccess $access,
        private readonly PresenceReader $reader,
        private readonly PresenceCheckOut $checkOut,
    ) {}

    /** Your own presence: the open session, or NOT_CHECKED_IN. */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        ['doctor' => $doctor] = $this->access->resolve($user, null, null);

        return ApiResponse::success($this->state($this->reader->openSession($doctor)));
    }

    /**
     * Check in at a clinic location.
     *
     * Needs an active assignment and an active service at the location, and the clinic open now or
     * within your shift window, which starts up to 60 minutes before the shift (422 with a `reason_code` such as
     * `no_assignment`, `outside_hours` otherwise). Already IN here: 200 with the current state. Open session in
     * another state or at another location: 409 `open_session`.
     */
    public function checkIn(CheckInRequest $request): JsonResponse
    {
        ['doctor' => $doctor, 'actor' => $actor] = $this->actor($request, $request->doctorId(), $request->locationId());

        $session = $this->sessions->checkIn($actor, $doctor, (string) $request->locationId(), $request->reason());

        // 200 with the current state when the doctor is already IN at this location (a retry); 201 for a new session.
        if (! $session->wasRecentlyCreated) {
            return ApiResponse::success($this->state($session));
        }

        return ApiResponse::success($this->state($session), status: 201);
    }

    /** Start a break. Only from IN (409 otherwise; already ON_BREAK: 200, unchanged). A reminder is sent at the expected end. */
    public function startBreak(StartBreakRequest $request): JsonResponse
    {
        ['doctor' => $doctor, 'actor' => $actor] = $this->actor($request, $request->doctorId(), $request->locationId());
        $scheduled = $request->validated('scheduled_break_id');

        return ApiResponse::success($this->state($this->sessions->startBreak($actor, $doctor, $request->locationId(), (int) $request->validated('expected_minutes'), is_string($scheduled) ? $scheduled : null)));
    }

    /** End a break. Only from ON_BREAK (409 otherwise; already IN: 200, unchanged). */
    public function endBreak(PresenceActionRequest $request): JsonResponse
    {
        ['doctor' => $doctor, 'actor' => $actor] = $this->actor($request, $request->doctorId(), $request->locationId());

        return ApiResponse::success($this->state($this->sessions->endBreak($actor, $doctor, $request->locationId())));
    }

    /** Mark yourself temporarily unavailable (TEMPORARILY_UNAVAILABLE). Only from IN (409 otherwise; already unavailable: 200, unchanged). */
    public function markUnavailable(MarkUnavailableRequest $request): JsonResponse
    {
        ['doctor' => $doctor, 'actor' => $actor] = $this->actor($request, $request->doctorId(), $request->locationId());

        return ApiResponse::success($this->state($this->sessions->markUnavailable($actor, $doctor, $request->locationId(), $request->reason())));
    }

    /** Become available again. Only from TEMPORARILY_UNAVAILABLE (409 otherwise; already IN: 200, unchanged). */
    public function markAvailable(PresenceActionRequest $request): JsonResponse
    {
        ['doctor' => $doctor, 'actor' => $actor] = $this->actor($request, $request->doctorId(), $request->locationId());

        return ApiResponse::success($this->state($this->sessions->markAvailable($actor, $doctor, $request->locationId())));
    }

    /**
     * Check-out preview (increment 5.8, D-I): what a check-out would find. Advisory, no lock, nothing changed. The caller needs
     * presence.check_out (for yourself) or presence.manage at the location (for someone else).
     */
    public function checkOutPreview(CheckOutPreviewRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        ['doctor' => $doctor, 'method' => $method] = $this->access->resolve($user, $request->doctorId(), $request->locationId(), 'presence.check_out');

        return ApiResponse::success(new CheckOutPreviewResource($this->checkOut->preview($user, $doctor, $request->locationId(), $method === PresenceChangeMethod::DoctorApp)));
    }

    /**
     * Check out of a clinic location (increment 5.8, D-J). Refused with 409 ACTIVE_CONSULTATION while a consultation is in progress or interrupted,
     * or a walk-in is called (`reason_code` says which). Never cancels an appointment; a walk-in entry is held or cancelled as the
     * `walkin_disposition` says. No Idempotency-Key: the open-session index and the state guard make a repeat a 409 `not_checked_in`.
     */
    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        ['doctor' => $doctor, 'actor' => $actor] = $this->actor($request, $request->doctorId(), $request->locationId(), 'presence.check_out');

        $outcome = $this->checkOut->checkOut($actor, $doctor, $request->locationId(), $request->reason(), $request->disposition(), $request->guestsInformed());

        return ApiResponse::success(new CheckOutResultResource($outcome['session'], $outcome['result']));
    }

    /** The doctor's own state with its effective status (derived BUSY). */
    private function state(?DoctorPresenceSession $session): PresenceStateResource
    {
        return new PresenceStateResource($session, $this->reader->effectiveStatus($session));
    }

    /** @return array{doctor: string, actor: PresenceActor} */
    private function actor(Request $request, ?string $doctorId, ?string $locationId, string $ownPermission = 'presence.check_in'): array
    {
        /** @var User $user */
        $user = $request->user();
        ['doctor' => $doctor, 'method' => $method] = $this->access->resolve($user, $doctorId, $locationId, $ownPermission);

        return ['doctor' => $doctor, 'actor' => new PresenceActor($user, $method, SourceDevice::of($request))];
    }
}
