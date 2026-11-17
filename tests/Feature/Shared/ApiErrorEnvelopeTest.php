<?php

use App\Shared\Enums\ErrorCode;
use App\Shared\Exceptions\DomainException;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    Route::get('/api/_test/ok', fn () => ApiResponse::success(['ping' => 'pong'], ['page' => 1]));

    Route::get('/api/_test/domain', function () {
        throw new class('That slot was just taken.') extends DomainException
        {
            public function errorCode(): ErrorCode
            {
                return ErrorCode::SlotUnavailable;
            }
        };
    });

    Route::post('/api/_test/validate', fn (Request $r) => $r->validate(['email' => ['required', 'email']]));

    Route::get('/api/_test/crash', function () {
        throw new RuntimeException('SQLSTATE[42P01]: relation "patients" does not exist; password=hunter2');
    });

    Route::get('/api/_test/throttled', fn () => abort(429, 'slow down', ['Retry-After' => '30']));
});

it('wraps successful responses in the envelope', function () {
    $this->getJson('/api/_test/ok')
        ->assertOk()
        ->assertExactJson(['success' => true, 'data' => ['ping' => 'pong'], 'meta' => ['page' => 1], 'message' => null]);
});

it('renders domain exceptions with their code, status and safe message', function () {
    $response = $this->getJson('/api/_test/domain');

    $response->assertStatus(409)->assertJson([
        'success' => false,
        'code' => 'SLOT_UNAVAILABLE',
        'message' => 'That slot was just taken.',
    ]);

    expect($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
});

it('renders validation errors as VALIDATION_FAILED with field errors', function () {
    $this->postJson('/api/_test/validate', ['email' => 'nope'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['errors' => ['email']]);
});

it('never leaks internal error details', function () {
    $response = $this->getJson('/api/_test/crash');

    $response->assertStatus(500)->assertJsonPath('code', 'SERVER_ERROR');

    expect($response->getContent())->not->toContain('SQLSTATE')
        ->not->toContain('patients')
        ->not->toContain('hunter2');
});

it('uses the envelope for unknown API routes and wrong methods', function () {
    $this->getJson('/api/_test/missing')->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
    $this->deleteJson('/api/_test/ok')->assertStatus(405)->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
});

it('keeps Retry-After on rate-limit responses', function () {
    $this->getJson('/api/_test/throttled')
        ->assertStatus(429)
        ->assertHeader('Retry-After', '30')
        ->assertJsonPath('code', 'RATE_LIMITED');
});

it('assigns a ULID request id when the client sends none or an invalid one', function (?string $header) {
    $headers = $header === null ? [] : ['X-Request-Id' => $header];

    $id = $this->getJson('/api/_test/ok', $headers)->headers->get('X-Request-Id');

    expect(Str::isUlid((string) $id))->toBeTrue()->and($id)->not->toBe($header);
})->with([null, 'not-an-id', "abc\nInjected: log line", str_repeat('a', 200)]);

it('keeps a valid client request id', function (string $id) {
    $this->getJson('/api/_test/ok', ['X-Request-Id' => $id])->assertHeader('X-Request-Id', $id);
})->with([(string) Str::ulid(), (string) Str::uuid()]);
