<?php

use App\Shared\Logging\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;

const R = RedactSensitiveData::REDACTED;

function log_record(string $message, array $context = []): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'test', Level::Info, $message, $context);
}

it('redacts sensitive keys at any depth, ignoring case and separators', function () {
    $record = (new RedactSensitiveData)(log_record('login', [
        'Password' => 'hunter2',
        'user' => ['email' => 'p@example.com', 'refresh-token' => 'abc', 'mfa' => ['totp' => '123456']],
        'headers' => ['Authorization' => 'Bearer xyz', 'X-XSRF-TOKEN' => 'csrf'],
        'membership_number' => 'M-0099887766',
        'hmo_membership_number' => 'M-0099887766',
        'diagnosis' => 'clinical text',
    ]));

    expect($record->context['Password'])->toBe(R)
        ->and($record->context['user']['refresh-token'])->toBe(R)
        ->and($record->context['user']['mfa']['totp'])->toBe(R)
        ->and($record->context['user']['email'])->toBe('p@example.com')
        ->and($record->context['headers']['Authorization'])->toBe(R)
        ->and($record->context['headers']['X-XSRF-TOKEN'])->toBe(R)
        ->and($record->context['membership_number'])->toBe(R)
        ->and($record->context['hmo_membership_number'])->toBe(R)
        ->and($record->context['diagnosis'])->toBe(R);
});

it('redacts generic words only as whole keys', function () {
    $record = (new RedactSensitiveData)(log_record('otp', [
        'code' => '482913',
        'error_code' => 'SLOT_UNAVAILABLE',
        'status_code' => 409,
        'notes' => 'free text',
    ]));

    expect($record->context['code'])->toBe(R)
        ->and($record->context['notes'])->toBe(R)
        ->and($record->context['error_code'])->toBe('SLOT_UNAVAILABLE')
        ->and($record->context['status_code'])->toBe(409);
});

it('scrubs secrets inside messages', function (string $message, string $expected) {
    expect(RedactSensitiveData::scrubMessage($message))->toBe($expected);
})->with([
    'bearer token' => ['Authorization: Bearer eyJhbGciOi.payload.sig', 'Authorization: Bearer '.R],
    'query string' => ['GET /reset?token=abc123&email=a@b.c', 'GET /reset?token='.R.'&email=a@b.c'],
    'json fragment' => ['{"password":"hunter2","name":"Ana"}', '{"password":'.R.',"name":"Ana"}'],
    'key value' => ['otp_code = 482913 sent', 'otp_code = '.R.' sent'],
    'error codes untouched' => ['error code=SLOT_UNAVAILABLE', 'error code=SLOT_UNAVAILABLE'],
]);

it('scrubs string values nested in context too', function () {
    $record = (new RedactSensitiveData)(log_record('x', ['url' => '/cb?access_token=abc']));

    expect($record->context['url'])->toBe('/cb?access_token='.R);
});
