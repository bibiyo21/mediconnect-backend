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
        // Walk-in registration (Phase 5, 5.5a).
        'doctor_not_active', 'location_not_active', 'remote_registration_off', 'mobile_not_verified', 'doctor_unavailable', 'doctor_checked_out', 'no_walkin_hours_today',
        'paused', 'closed', 'cutoff_passed', 'clinic_closed_today', 'live_entries_per_patient', 'patients_per_account_per_day',
    ];

    /** Business refusals of the walk-in routes, by operation (path suffix and method). */
    private const WALK_IN_409 = [
        'POST walk-ins' => 'QUEUE_NOT_STARTED (the doctor has not checked in; patient app only), REGISTRATION_NOT_ALLOWED (reason_code: remote_registration_off, mobile_not_verified, doctor_unavailable, doctor_checked_out, no_walkin_hours_today, doctor_not_active, location_not_active), QUEUE_CLOSED (reason_code: paused, closed, cutoff_passed, clinic_closed_today), QUEUE_FULL (patient app), CAPACITY_OVERRIDE_REQUIRED (staff and doctor: errors.current and errors.capacity; resend with capacity_override), ENTRY_ALREADY_LIVE (errors.entry_id), DAILY_LIMIT_REACHED (reason_code: live_entries_per_patient or patients_per_account_per_day; errors.limit), LATE_RISK_ACK_REQUIRED (send acknowledge_late_risk), CONCURRENT_MODIFICATION (retry with the same key: it is released). Reuse the Idempotency-Key only for a retry of the same body: any other 4xx is replayed for 48 hours.',
        'POST walk-ins/{id}/arrive' => 'INVALID_STATE_TRANSITION: the entry is not REGISTERED (errors.status is its current status), or CONCURRENT_MODIFICATION (retry).',
        'POST walk-ins/{id}/cancel' => 'INVALID_STATE_TRANSITION: only a REGISTERED or WAITING entry can be cancelled (errors.status is its current status), or CONCURRENT_MODIFICATION (retry).',
        'POST walk-ins/queues/{id}/pause' => 'INVALID_STATE_TRANSITION: only an OPEN queue can be paused (errors.status), or CONCURRENT_MODIFICATION (retry).',
        'POST walk-ins/queues/{id}/open' => 'INVALID_STATE_TRANSITION: only a PAUSED queue can be reopened; a closed queue stays closed (errors.status), or CONCURRENT_MODIFICATION (retry).',
        'PATCH clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy' => 'INVALID_STATE_TRANSITION: the assignment has ended.',
    ];

    /** Walk-in operations that answer 404 for a missing record or one the caller has no standing at. */
    private const WALK_IN_404 = ['GET walk-ins/{id}', 'POST walk-ins/{id}/arrive', 'POST walk-ins/{id}/cancel', 'POST walk-ins/queues/{id}/pause', 'POST walk-ins/queues/{id}/open',
        'GET clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy', 'PATCH clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy'];

    /** Operations that need an Idempotency-Key header (the `idempotent` middleware): 400 when it is missing. */
    private const WALK_IN_IDEMPOTENT = ['POST walk-ins', 'POST walk-ins/{id}/arrive', 'POST walk-ins/{id}/cancel'];

    /** Operations with no request body or query to validate: Scramble would add a 422 that can never happen. */
    private const WALK_IN_NO_422 = ['POST walk-ins/{id}/arrive', 'POST walk-ins/queues/{id}/pause', 'POST walk-ins/queues/{id}/open', 'GET walk-ins/{id}'];

    public function __invoke(OpenApi $openApi): void
    {
        $envelope = $this->envelope();
        $openApi->components->addSchema('ErrorEnvelope', Schema::fromType($envelope));
        $ref = new Reference('schemas', 'ErrorEnvelope', $openApi->components);

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $method => $operation) {
                $description = self::WALK_IN_409[strtoupper((string) $method).' '.ltrim($path->path, '/')] ?? null;
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

                $operation->addResponse(Response::make(409)->setContent('application/json', $ref)->setDescription($isCheckIn
                    ? 'INVALID_STATE_TRANSITION, reason_code `open_session`: the doctor already has an open session in another state or at another location (errors.clinic_location_id only for the doctor and staff of that location), or `change_in_progress` (CONCURRENT_MODIFICATION): retry.'
                    : 'INVALID_STATE_TRANSITION: the move is not allowed from the current status (reason_code `not_checked_in` or `invalid_transition`; errors.status is the current status), or `change_in_progress` (CONCURRENT_MODIFICATION): retry. A repeat of the state already reached is a 200, not a 409.'));

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

        if (in_array($key, self::WALK_IN_404, true)) {
            $add(404, 'NOT_FOUND: no such record, or the caller has no standing at it (its existence is not revealed).');
        }
        if ($key === 'GET clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy') {
            $add(403, 'FORBIDDEN: clinics.update is needed at the assignment\'s location.');
        }
        if (in_array($key, self::WALK_IN_IDEMPOTENT, true)) {
            $add(400, 'BAD_REQUEST: the Idempotency-Key header (a UUID or ULID) is missing. Reuse a key only to retry the same request; any 4xx answer is replayed for 48 hours.');
            $parameter = (new Parameter('Idempotency-Key', 'header'))->setSchema(Schema::fromType((new StringType)->format('uuid')))->description('A new UUID or ULID for each distinct request; the same one when retrying it.');
            $parameter->required = true;
            $operation->addParameters([$parameter]);
        }
        if (in_array($key, self::WALK_IN_NO_422, true)) {
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
