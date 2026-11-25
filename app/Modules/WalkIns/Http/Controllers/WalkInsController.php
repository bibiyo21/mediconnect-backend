<?php

namespace App\Modules\WalkIns\Http\Controllers;

use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\DTOs\RegistrationCommand;
use App\Modules\WalkIns\Enums\CancelReason;
use App\Modules\WalkIns\Enums\PayerType;
use App\Modules\WalkIns\Enums\PriorityLevel;
use App\Modules\WalkIns\Enums\RegistrationChannel;
use App\Modules\WalkIns\Enums\ViewLevel;
use App\Modules\WalkIns\Http\Requests\CancelWalkInRequest;
use App\Modules\WalkIns\Http\Requests\EntryActionRequest;
use App\Modules\WalkIns\Http\Requests\JoinPreviewRequest;
use App\Modules\WalkIns\Http\Requests\QueueBoardRequest;
use App\Modules\WalkIns\Http\Requests\RegisterWalkInRequest;
use App\Modules\WalkIns\Http\Requests\SearchWalkInsRequest;
use App\Modules\WalkIns\Http\Resources\JoinPreviewResource;
use App\Modules\WalkIns\Http\Resources\QueueBoardResource;
use App\Modules\WalkIns\Http\Resources\WalkInEntryResource;
use App\Modules\WalkIns\Models\WalkInQueueEntry;
use App\Modules\WalkIns\Services\EntryActions;
use App\Modules\WalkIns\Services\EntryPresenter;
use App\Modules\WalkIns\Services\JoinPreview;
use App\Modules\WalkIns\Services\WalkInAccess;
use App\Modules\WalkIns\Services\WalkInReader;
use App\Modules\WalkIns\Services\WalkInRegistration;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Walk-in queues (increment 5.5a, api-specification.md §2.9). A walk-in queue number is a place in a queue, not an appointment, and
 * a wait is an estimate, never a promise. No payment is taken and no HMO coverage is verified. Every write is guarded by a database
 * constraint or lock, never by Redis.
 */
final class WalkInsController
{
    public function __construct(
        private readonly WalkInAccess $access,
        private readonly WalkInRegistration $registration,
        private readonly WalkInReader $reader,
        private readonly EntryPresenter $presenter,
        private readonly EntryActions $actions,
        private readonly JoinPreview $preview,
    ) {}

    /**
     * Register for a walk-in queue.
     *
     * Needs an `Idempotency-Key` (a retry with the same key and body returns the same entry). Send `patient_profile_id` for yourself or a
     * dependant you manage (patient path), or `guest` for a person the clinic registers at the desk (staff or doctor path). The server decides
     * the channel, the queue, the number and the fee. Patient-app registration is off until the clinic turns it on, needs your verified mobile
     * number and the doctor to be checked in, and is limited per day. `409` codes: QUEUE_NOT_STARTED, REGISTRATION_NOT_ALLOWED (reason_code),
     * QUEUE_CLOSED (reason_code), QUEUE_FULL, CAPACITY_OVERRIDE_REQUIRED, ENTRY_ALREADY_LIVE (errors.entry_id), DAILY_LIMIT_REACHED (reason_code),
     * LATE_RISK_ACK_REQUIRED, CONCURRENT_MODIFICATION (retry). The estimate is a range or nothing, never a promise.
     */
    public function store(RegisterWalkInRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $service = DoctorService::query()->where('consultation_type', 'IN_PERSON')->whereNotNull('clinic_location_id')->find((string) $request->validated('doctor_service_id'));
        if ($service === null) {
            throw ValidationException::withMessages(['doctor_service_id' => ['This service is not available for walk-ins at this location.']]);
        }

        if ($request->isPatientPath()) {
            $profile = PatientProfile::query()->find((string) $request->validated('patient_profile_id'));
            // Yourself or a guardian-authorized dependant; anything else, including an unknown id, is the same 403.
            abort_unless($user->can('walkins.register') && $profile !== null && Gate::forUser($user)->allows('view', $profile), 403, 'You can register only yourself and dependants you manage.');
            $channel = RegistrationChannel::PatientApp;
            $level = ViewLevel::Owner;
        } else {
            $profile = null;
            $channel = $this->access->actingChannel($user, 'walkins.register', (string) $service->clinic_location_id, $service->doctor_profile_id);
            abort_if($channel === null, 403, 'You do not have access to this location.');
            $level = $channel === RegistrationChannel::DoctorApp ? ViewLevel::Doctor : ViewLevel::Staff;
        }

        $command = new RegistrationCommand(
            $user, $channel, $service, (string) $request->header('Idempotency-Key'), PayerType::from((string) $request->validated('payer_type')),
            $profile,
            $profile === null ? (string) $request->validated('guest.name') : null,
            $profile === null ? $request->guestPhoneE164() : null,
            $request->filled('hmo_card_name_text') ? (string) $request->validated('hmo_card_name_text') : null,
            $profile === null ? $request->priority() : PriorityLevel::Normal,
            $profile === null ? $request->priorityReason() : null,
            $profile === null && $request->filled('priority_note') ? (string) $request->validated('priority_note') : null,
            $profile === null ? (string) $request->validated('privacy_notice_version') : null,
            $profile === null && $request->boolean('capacity_override'),
            $request->boolean('acknowledge_late_risk'),
        );

        $result = $this->registration->register($command);
        $view = $this->presenter->one($result->entry->setRelation('queue', $result->queue), $level, estimate: $result->estimate, withEstimate: $result->estimate !== null || $result->replayed);
        $response = ApiResponse::success(new WalkInEntryResource($view), status: 201);

        return $result->replayed ? $response->header('Idempotency-Replayed', 'true') : $response;
    }

    /**
     * The walk-in board, or your own places.
     *
     * With `clinic_location_id`: the board of that location for the date, for its staff and for the queue's doctor (names; minutes for the next
     * waiting entries only); global-scope readers get a restricted view without names, and every such read is audited. Without it: your own
     * entries for the date and no one else's.
     */
    public function queue(QueueBoardRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $request->filled('clinic_location_id')) {
            return ApiResponse::success(WalkInEntryResource::collection($this->reader->mine($user, $request->validated('date'))));
        }

        $location = ClinicLocation::query()->findOrFail((string) $request->validated('clinic_location_id'));
        $board = $this->reader->board($user, $location, $request->validated('doctor_id'), $request->validated('date'));

        return ApiResponse::success(new QueueBoardResource($board));
    }

    /**
     * Before joining: queue state, whether the app can register you, the listed fee and an estimate.
     *
     * No counts and no remaining places. The estimate is a range or nothing and is withheld when it would be a guess about a guess.
     */
    public function joinPreview(JoinPreviewRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $service = DoctorService::query()->where('consultation_type', 'IN_PERSON')->whereNotNull('clinic_location_id')->find((string) $request->validated('doctor_service_id'));
        if ($service === null) {
            throw ValidationException::withMessages(['doctor_service_id' => ['This service is not available for walk-ins at this location.']]);
        }

        return ApiResponse::success(new JoinPreviewResource($this->preview->for($user, $service)));
    }

    /**
     * Staff dedupe search over a location's live entries today.
     *
     * By queue number, exact phone or part of a name. Names are matched in memory over at most 500 entries. A phone is never returned.
     * The search is audited by type and result count only.
     */
    public function search(SearchWalkInsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $term = (string) $request->validated('term');
        if (WalkInReader::termIsBadPhone($term)) {
            throw ValidationException::withMessages(['term' => ['Enter a valid Philippine mobile number.']]);
        }
        if (WalkInReader::termType($term) === 'name' && mb_strlen(trim($term)) < 2) {
            throw ValidationException::withMessages(['term' => ['Enter at least 2 characters of a name.']]);
        }
        $location = ClinicLocation::query()->findOrFail((string) $request->validated('clinic_location_id'));
        $found = $this->reader->search($user, $location, $term);

        return ApiResponse::success(WalkInEntryResource::collection($found['entries']), meta: ['match_type' => $found['type'], 'truncated' => $found['truncated']]);
    }

    /**
     * One entry.
     *
     * Your own entry (you registered it, or it is yours or a dependant's), or, for the location's staff, the detail with the guest's name and
     * phone (audited). Anyone else gets 404.
     */
    public function show(EntryActionRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);

        return ApiResponse::success(new WalkInEntryResource($this->reader->show($user, $entry)));
    }

    /** Mark an entry as arrived (REGISTERED to WAITING). Needs `walkins.manage_queue` at the location. Needs an `Idempotency-Key`. */
    public function arrive(EntryActionRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);
        $done = $this->actions->arrive($user, $entry);
        $level = $this->access->viewLevel($user, $entry->queue->clinic_location_id, $entry->queue->doctor_profile_id);

        return ApiResponse::success(new WalkInEntryResource($this->presenter->one($done->setRelation('queue', $entry->queue), $level === ViewLevel::None ? ViewLevel::Doctor : $level)));
    }

    /**
     * Cancel an entry that is REGISTERED or WAITING.
     *
     * By its owner, or by staff or the doctor with `walkins.manage_queue`. Staff cancelling a guest must confirm `patient_informed_in_person`.
     * Needs an `Idempotency-Key`.
     */
    public function cancel(CancelWalkInRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entry = WalkInQueueEntry::query()->with('queue')->findOrFail($id);
        $done = $this->actions->cancel($user, $entry, CancelReason::from((string) $request->validated('reason_code')), $request->boolean('patient_informed_in_person'));
        $level = $this->access->viewLevel($user, $entry->queue->clinic_location_id, $entry->queue->doctor_profile_id);

        return ApiResponse::success(new WalkInEntryResource($this->presenter->one($done->setRelation('queue', $entry->queue), $level === ViewLevel::None || ! $level->seesNames() ? ViewLevel::Owner : $level)));
    }
}
