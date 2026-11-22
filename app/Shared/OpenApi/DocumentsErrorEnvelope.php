<?php

namespace App\Shared\OpenApi;

use App\Shared\Enums\ErrorCode;
use Dedoc\Scramble\Support\Generator\OpenApi;
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
    ];

    public function __invoke(OpenApi $openApi): void
    {
        $envelope = $this->envelope();
        $openApi->components->addSchema('ErrorEnvelope', Schema::fromType($envelope));
        $ref = new Reference('schemas', 'ErrorEnvelope', $openApi->components);

        foreach ($openApi->paths as $path) {
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
