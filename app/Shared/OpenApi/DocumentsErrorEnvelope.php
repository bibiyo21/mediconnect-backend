<?php

namespace App\Shared\OpenApi;

use App\Shared\Enums\ErrorCode;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Publishes the §23 error envelope as the `ErrorEnvelope` schema (with the optional `reason_code`) and attaches
 * the business errors Scramble cannot infer from code (409 and domain 422) to the routes named below. Scramble
 * already documents the generic 401, 403, 422 and 429 from middleware and form requests.
 */
final class DocumentsErrorEnvelope
{
    /** Domain refusals of POST /presence/check-in, in the order the rules are checked. */
    private const CHECK_IN_REASONS = 'doctor_not_active, location_not_active, no_assignment, no_service_mode, clinic_closed, closed_holiday, outside_hours';

    /**
     * Every reason_code the API sends today (presence, Phase 5). Add a code here when another module starts sending one.
     *
     * @var list<string>
     */
    private const REASON_CODES = [
        'doctor_not_active', 'location_not_active', 'no_assignment', 'no_service_mode', 'clinic_closed', 'closed_holiday', 'outside_hours',
        'open_session', 'not_checked_in', 'invalid_transition', 'change_in_progress',
        // Doctor check-out (Phase 5, 5.8-1): the blocks that refuse it.
        'in_progress', 'interrupted', 'called',
        // Walk-in registration (Phase 5, 5.5a).
        'doctor_not_active', 'location_not_active', 'remote_registration_off', 'mobile_not_verified', 'doctor_unavailable', 'doctor_checked_out', 'no_walkin_hours_today',
        'paused', 'closed', 'cutoff_passed', 'clinic_closed_today', 'live_entries_per_patient', 'patients_per_account_per_day',
        // Walk-in queue operations (Phase 5, 5.6-2).
        'on_break', 'unavailable', 'checked_out', 'not_today', 'appointment', 'hold', 'no_doctor', 'other_queue', 'previous_day',
        // Queue close and transfer (Phase 5, 5.8-2): the doctor is checked in at the location; the cutoff of a target queue is `cutoff_passed` (listed above).
        'doctor_checked_in',
    ];

    /** Business refusals of the walk-in routes, by operation (path suffix and method). */
    private const WALK_IN_409 = [
        'POST walk-ins' => 'QUEUE_NOT_STARTED (the doctor has not checked in; patient app only), REGISTRATION_NOT_ALLOWED (reason_code: remote_registration_off, mobile_not_verified, doctor_unavailable, doctor_checked_out, no_walkin_hours_today, doctor_not_active, location_not_active), QUEUE_CLOSED (reason_code: paused, closed, cutoff_passed, clinic_closed_today), QUEUE_FULL (patient app), CAPACITY_OVERRIDE_REQUIRED (staff and doctor: errors.current and errors.capacity; resend with capacity_override), ENTRY_ALREADY_LIVE (errors.entry_id), DAILY_LIMIT_REACHED (reason_code: live_entries_per_patient or patients_per_account_per_day; errors.limit), LATE_RISK_ACK_REQUIRED (send acknowledge_late_risk), CONCURRENT_MODIFICATION (retry with the same key: it is released). Reuse the Idempotency-Key only for a retry of the same body: any other 4xx is replayed for 48 hours.',
        'POST walk-ins/{id}/arrive' => 'INVALID_STATE_TRANSITION: the entry is not REGISTERED (errors.status is its current status), or CONCURRENT_MODIFICATION (retry).',
        'POST walk-ins/{id}/cancel' => 'INVALID_STATE_TRANSITION: only a REGISTERED, WAITING or SKIPPED entry can be cancelled (errors.status is its current status), or CONCURRENT_MODIFICATION (retry).',
        'POST walk-ins/queues/{id}/pause' => 'INVALID_STATE_TRANSITION: only an OPEN queue can be paused (errors.status), or CONCURRENT_MODIFICATION (retry).',
        'POST walk-ins/queues/{id}/close' => 'INVALID_STATE_TRANSITION (reason_code doctor_checked_in while the doctor is checked in at the location; errors.status when the queue is already CLOSED), ACTIVE_CONSULTATION (reason_code called or in_progress, errors.entry_id: a CALLED or IN_CONSULTATION entry), or CONCURRENT_MODIFICATION (retry with a NEW key). 422 (errors.dispositions) when a live entry has no disposition, or a guest is given CANCEL_WITH_NOTICE.',
        'POST walk-ins/{id}/transfer' => 'INVALID_STATE_TRANSITION (the entry is not REGISTERED or WAITING; errors.status), DOCTOR_NOT_AVAILABLE (reason_code checked_out, no_assignment or no_doctor: the target doctor), REGISTRATION_NOT_ALLOWED (reason_code: doctor_not_active, location_not_active, no_walkin_hours_today, doctor_unavailable or doctor_checked_out: the target is not taking walk-ins), QUEUE_CLOSED (reason_code paused, closed, cutoff_passed, clinic_closed_today or not_today: the target is not open today), ENTRY_ALREADY_LIVE (errors.entry_id: the patient already holds a place in the target), CAPACITY_OVERRIDE_REQUIRED (send over_capacity_ack), or CONCURRENT_MODIFICATION (retry with a NEW key). A target the caller cannot see is 404.',
        'POST walk-ins/queues/{id}/open' => 'INVALID_STATE_TRANSITION: only a PAUSED queue can be reopened; a closed queue stays closed (errors.status), or CONCURRENT_MODIFICATION (retry).',
        'POST walk-ins/queues/{id}/call-next' => 'DOCTOR_NOT_AVAILABLE (reason_code: on_break, unavailable, not_checked_in, checked_out, doctor_not_active, location_not_active, no_assignment, no_doctor: the queue has no doctor), QUEUE_CLOSED (reason_code: closed, not_today), ENTRY_ALREADY_CALLED (errors.entry_id), DOCTOR_ALREADY_CALLING (reason_code: other_queue, previous_day; no ids), QUEUE_EMPTY, APPOINTMENT_WINDOW_PROTECTED (reason_code: appointment or hold; errors.blocked_until), or CONCURRENT_MODIFICATION (retry with a NEW key). Call-next never overrides a protected window. Any 4xx answer is replayed for 48 hours for the same key, so send a NEW Idempotency-Key for each attempt.',
        'POST walk-ins/{id}/call' => 'INVALID_STATE_TRANSITION (the entry is not WAITING; errors.status), NOT_NEXT_IN_ORDER, DOCTOR_NOT_AVAILABLE (reason_code: on_break, unavailable, not_checked_in, checked_out, doctor_not_active, location_not_active, no_assignment, no_doctor), QUEUE_CLOSED (reason_code: closed, not_today), ENTRY_ALREADY_CALLED (errors.entry_id), DOCTOR_ALREADY_CALLING (reason_code: other_queue, previous_day; no ids), APPOINTMENT_WINDOW_PROTECTED (reason_code: appointment or hold; errors.blocked_until; a triaged patient can pass it with override_reason_code), or CONCURRENT_MODIFICATION (retry with a NEW key).',
        'POST walk-ins/{id}/recall' => 'INVALID_STATE_TRANSITION (the entry is not CALLED; errors.status), QUEUE_CLOSED (reason_code: closed or not_today), RECALL_LIMIT_REACHED (nothing changes by itself afterwards), or CONCURRENT_MODIFICATION (retry with a NEW key).',
        'POST walk-ins/{id}/skip' => 'INVALID_STATE_TRANSITION: only a CALLED entry can be skipped (errors.status), or CONCURRENT_MODIFICATION (retry with a NEW key).',
        'POST walk-ins/{id}/no-show' => 'INVALID_STATE_TRANSITION: a SKIPPED entry, or a CALLED one once the recall limit is reached (errors.status), or CONCURRENT_MODIFICATION (retry with a NEW key).',
        'POST walk-ins/{id}/return' => 'INVALID_STATE_TRANSITION (the entry is not SKIPPED; errors.status), QUEUE_CLOSED (reason_code: closed or not_today), ENTRY_ALREADY_LIVE (errors.entry_id), or CONCURRENT_MODIFICATION (retry with a NEW key).',
        'POST walk-ins/{id}/priority' => 'INVALID_STATE_TRANSITION: only a WAITING or SKIPPED entry of a guest whose data was not purged can be given a new priority (errors.status), QUEUE_CLOSED (reason_code: closed or not_today), or CONCURRENT_MODIFICATION (retry with a NEW key).',
        'PATCH clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy' => 'INVALID_STATE_TRANSITION: the assignment has ended.',
    ];

    /** Walk-in operations that answer 404 for a missing record or one the caller has no standing at. */
    public const WALK_IN_404 = ['GET walk-ins/{id}', 'POST walk-ins/{id}/arrive', 'POST walk-ins/queues/{id}/close', 'POST walk-ins/{id}/transfer', 'POST walk-ins/{id}/cancel', 'POST walk-ins/queues/{id}/pause', 'POST walk-ins/queues/{id}/open',
        'POST walk-ins/queues/{id}/call-next', 'POST walk-ins/{id}/call', 'POST walk-ins/{id}/recall', 'POST walk-ins/{id}/skip', 'POST walk-ins/{id}/no-show', 'POST walk-ins/{id}/return', 'POST walk-ins/{id}/priority',
        'GET clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy', 'PATCH clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy'];

    /** Operations that need an Idempotency-Key header (the `idempotent` middleware): 400 when it is missing. */
    public const WALK_IN_IDEMPOTENT = ['POST walk-ins', 'POST walk-ins/{id}/arrive', 'POST walk-ins/{id}/cancel',
        'POST walk-ins/queues/{id}/call-next', 'POST walk-ins/{id}/call', 'POST walk-ins/{id}/recall', 'POST walk-ins/{id}/skip', 'POST walk-ins/{id}/no-show', 'POST walk-ins/{id}/return', 'POST walk-ins/{id}/priority',
        'POST walk-ins/queues/{id}/close', 'POST walk-ins/{id}/transfer'];

    /** The consultation writes that take a 404 or 403 and an Idempotency-Key (5.7). */
    private const CONSULTATION_WRITES = ['POST consultations/{id}/start', 'POST consultations/{id}/end', 'POST consultations/{id}/interrupt',
        'POST consultations/{id}/resume', 'POST walk-ins/{id}/complete'];

    /** Consultation refusals (Phase 5, 5.7), by operation. The reason codes are the presence and queue ones already in REASON_CODES. */
    private const CONSULTATION_409 = [
        'POST consultations/{id}/start' => 'DOCTOR_NOT_AVAILABLE (reason_code: checked_out, on_break, unavailable, not_checked_in, no_assignment, doctor_not_active or location_not_active: the doctor is not stored IN at this location, or the doctor or location is not active), QUEUE_CLOSED (reason_code: closed or not_today), INVALID_STATE_TRANSITION (errors.status: the consultation or the entry is not in a state that allows the start), ACTIVE_CONSULTATION (the doctor already has a consultation in progress), or CONCURRENT_MODIFICATION (retry with a NEW key). A repeat of a start that already happened is a 200, not a 409. A doctor\'s own patient profile is refused with 403.',
        'POST consultations/{id}/end' => 'INVALID_STATE_TRANSITION (the consultation is not IN_PROGRESS or INTERRUPTED; errors.status), or CONCURRENT_MODIFICATION (retry with a NEW key). Ending never refuses on presence. A repeat of an end that already happened is a 200, not a 409.',
        'POST walk-ins/{id}/complete' => 'INVALID_STATE_TRANSITION (the consultation of this entry is not IN_PROGRESS or INTERRUPTED; errors.status), or CONCURRENT_MODIFICATION (retry with a NEW key). The same rules as the consultation end.',
        'POST consultations/{id}/interrupt' => 'INVALID_STATE_TRANSITION (the consultation is not IN_PROGRESS; errors.status), or CONCURRENT_MODIFICATION (retry with a NEW key). Never refused on presence. A repeat is a 200.',
        'POST consultations/{id}/resume' => 'DOCTOR_NOT_AVAILABLE (reason_code: checked_out, on_break, unavailable or not_checked_in: the doctor is not stored IN at this location; no_assignment, doctor_not_active or location_not_active: the doctor is no longer assigned, or the doctor or location is no longer active), QUEUE_CLOSED (reason_code: not_today: the walk-in queue is not today\'s), ACTIVE_CONSULTATION (another consultation of the doctor is in progress), INVALID_STATE_TRANSITION (errors.status), or CONCURRENT_MODIFICATION (retry with a NEW key). A repeat is a 200.',
    ];

    /** Operations with no request body or query to validate: Scramble would add a 422 that can never happen. */
    private const WALK_IN_NO_422 = ['POST walk-ins/{id}/arrive', 'POST walk-ins/queues/{id}/pause', 'POST walk-ins/queues/{id}/open', 'GET walk-ins/{id}',
        'POST walk-ins/{id}/recall', 'POST walk-ins/{id}/skip', 'POST walk-ins/{id}/no-show', 'POST walk-ins/{id}/return'];

    public function __invoke(OpenApi $openApi): void
    {
        $envelope = $this->envelope();
        $openApi->components->addSchema('ErrorEnvelope', Schema::fromType($envelope));
        $ref = new Reference('schemas', 'ErrorEnvelope', $openApi->components);

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $method => $operation) {
                $description = self::WALK_IN_409[strtoupper((string) $method).' '.ltrim($path->path, '/')] ?? self::CONSULTATION_409[strtoupper((string) $method).' '.ltrim($path->path, '/')] ?? null;
                if ($description !== null) {
                    $operation->addResponse(Response::make(409)->setContent('application/json', $ref)->setDescription($description));
                }
                $this->documentWalkInOperation($operation, strtoupper((string) $method).' '.ltrim($path->path, '/'), $ref);
            }
            if (! str_contains($path->path, 'presence/')) {
                continue;
            }
            foreach ($path->operations as $method => $operation) {
                $isCheckIn = str_ends_with($path->path, 'presence/check-in');
                if (strtolower((string) $method) === 'get') {
                    if (str_contains($path->path, 'presence/doctors/')) {
                        $operation->addResponse(Response::make(404)->setDescription('No active, listed doctor with this id.')->setContent('application/json', $ref));
                    }

                    continue;
                }

                $isCheckOut = str_ends_with($path->path, 'presence/check-out');
                $activeNote = str_ends_with($path->path, 'presence/unavailable') ? ' ACTIVE_CONSULTATION: a consultation is in progress; end it first (errors carry no ids).' : '';
                if (str_ends_with($path->path, 'presence/break/start')) {
                    $activeNote = ' ACTIVE_CONSULTATION (reason_code `in_progress` or `called`): a consultation is in progress, or a walk-in patient is called to this doctor; end it, or start, skip or mark that patient no-show first (no ids).';
                }
                if ($isCheckOut) {
                    $activeNote = ' ACTIVE_CONSULTATION (reason_code `in_progress`, `interrupted` or `called`): a consultation is in progress or interrupted, or a walk-in patient is called; end it, or start, skip or mark that patient no-show first (no ids).';
                }
                $baseNote = $isCheckIn ? 'INVALID_STATE_TRANSITION, reason_code `open_session`: the doctor already has an open session in another state or at another location (errors.clinic_location_id only for the doctor and staff of that location), or `change_in_progress` (CONCURRENT_MODIFICATION): retry.'
                    : ($isCheckOut ? 'INVALID_STATE_TRANSITION, reason_code `not_checked_in`: no open session at this location (a retry after a success lands here: refetch GET /presence/me), or `change_in_progress` (CONCURRENT_MODIFICATION): retry.'
                    : 'INVALID_STATE_TRANSITION: the move is not allowed from the current status (reason_code `not_checked_in` or `invalid_transition`; errors.status is the current status), or `change_in_progress` (CONCURRENT_MODIFICATION): retry. A repeat of the state already reached is a 200, not a 409.');
                $operation->addResponse(Response::make(409)->setContent('application/json', $ref)->setDescription(trim($activeNote.' '.$baseNote)));

                if ($isCheckIn) {
                    $operation->responses = array_values(array_filter($operation->responses ?? [], fn ($r) => ! ($r instanceof Reference && $r->fullName === 'ValidationException')));
                    $operation->addResponse(Response::make(422)->setContent('application/json', $ref)->setDescription('VALIDATION_FAILED. For a check-in the rules refused, reason_code is one of: '.self::CHECK_IN_REASONS.'; otherwise errors lists the invalid fields.'));
                }
            }
        }
    }

    /** @param  Reference  $ref  the ErrorEnvelope schema */
    private function documentWalkInOperation(Operation $operation, string $key, Reference $ref): void
    {
        $has = fn (int $code) => collect($operation->responses ?? [])->contains(fn ($r) => $r instanceof Response && (int) $r->code === $code);
        $add = function (int $code, string $description) use ($operation, $ref, $has): void {
            if (! $has($code)) {
                $operation->addResponse(Response::make($code)->setContent('application/json', $ref)->setDescription($description));
            }
        };

        if (in_array($key, self::CONSULTATION_WRITES, true)) {
            $add(404, 'NOT_FOUND: no such consultation, or the caller has no standing at it (its existence is not revealed). For complete: no consultation exists for this entry.');
            $add(403, 'FORBIDDEN: the caller can see the consultation but may not start or end it (only its own doctor may).');
        }
        if (in_array($key, self::WALK_IN_404, true)) {
            $add(404, 'NOT_FOUND: no such record, or the caller has no standing at it (its existence is not revealed).');
        }
        if ($key === 'GET consultations/{id}') {
            $add(404, 'NOT_FOUND: no such consultation, or the caller has no standing at it (its existence is not revealed).');
        }
        if (in_array($key, ['GET consultations', 'GET consultations/{id}'], true)) {
            $add(403, 'FORBIDDEN: the caller lacks consultations.view (FINANCE_OFFICER and SUPER_ADMIN have no standing here).');
        }
        if ($key === 'GET clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy') {
            $add(403, 'FORBIDDEN: clinics.update is needed at the assignment\'s location.');
        }
        if (in_array($key, self::WALK_IN_IDEMPOTENT, true) || in_array($key, self::CONSULTATION_WRITES, true)) {
            $add(400, 'BAD_REQUEST: the Idempotency-Key header (a UUID or ULID) is missing. Reuse a key only to retry the same request; any 4xx answer is replayed for 48 hours.');
            $parameter = (new Parameter('Idempotency-Key', 'header'))->setSchema(Schema::fromType((new StringType)->format('uuid')))->description('A new UUID or ULID for each distinct request; the same one when retrying it.');
            $parameter->required = true;
            $operation->addParameters([$parameter]);
        }
        if (in_array($key, self::WALK_IN_NO_422, true) || in_array($key, self::CONSULTATION_WRITES, true)) {
            $operation->responses = array_values(array_filter($operation->responses ?? [], fn ($r) => ! ($r instanceof Reference && str_contains((string) $r->fullName, 'ValidationException')) && ! ($r instanceof Response && (int) $r->code === 422)));
        }
    }

    private function envelope(): ObjectType
    {
        $code = (new StringType)->enum(array_map(fn (ErrorCode $c) => $c->value, ErrorCode::cases()))->setDescription('Stable machine-readable error code.');

        $messages = new ArrayType;
        $messages->items = new StringType;

        return (new ObjectType)
            ->addProperty('success', (new BooleanType)->enum([false]))
            ->addProperty('message', (new StringType)->setDescription('Safe to show; never personal or clinical data.'))
            ->addProperty('errors', (new ObjectType)->additionalProperties($messages)->setDescription('Field name to a list of messages, or a state hint such as {"status": ["IN"]}.'))
            ->addProperty('code', $code)
            ->addProperty('reason_code', (new StringType)->enum(self::REASON_CODES)->setDescription('Optional finer reason inside the code, for example `no_assignment` or `open_session`. Absent when the code says it all.'))
            ->addProperty('request_id', (new StringType)->nullable(true))
            ->setRequired(['success', 'message', 'errors', 'code', 'request_id']);
    }
}
