<?php

use App\Shared\Enums\ErrorCode;

it('maps every error code to an HTTP error status and a message', function (ErrorCode $code) {
    expect($code->httpStatus())->toBeGreaterThanOrEqual(400)->toBeLessThan(600)
        ->and($code->defaultMessage())->not->toBeEmpty();
})->with(ErrorCode::cases());

it('uses the statuses from the API specification', function () {
    expect(ErrorCode::SlotUnavailable->httpStatus())->toBe(409)
        ->and(ErrorCode::IdempotencyKeyReused->httpStatus())->toBe(422)
        ->and(ErrorCode::RecordFinalized->httpStatus())->toBe(423)
        ->and(ErrorCode::StepUpRequired->httpStatus())->toBe(401)
        ->and(ErrorCode::DependencyUnavailable->httpStatus())->toBe(503);
});
