<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->calls = 0;
    Route::middleware(['api', 'idempotent'])->post('/api/v1/_test/orders', function () {
        $this->calls++;

        return response()->json(['order' => $this->calls, 'item' => request('item')], 201);
    });
    Route::middleware(['api', 'idempotent'])->post('/api/v1/_test/broken', fn () => abort(500));
});

it('requires an Idempotency-Key header', function (array $headers) {
    $this->postJson('/api/v1/_test/orders', ['item' => 'a'], $headers)->assertStatus(400)->assertJsonPath('code', 'BAD_REQUEST');
    expect($this->calls)->toBe(0);
})->with([[[]], [['Idempotency-Key' => 'not-a-uuid']]]);

it('replays the stored response for a retry with the same key and body', function () {
    $key = (string) Str::uuid7();

    $first = $this->postJson('/api/v1/_test/orders', ['item' => 'a', 'qty' => 1], ['Idempotency-Key' => $key])->assertCreated();
    $retry = $this->postJson('/api/v1/_test/orders', ['qty' => 1, 'item' => 'a'], ['Idempotency-Key' => $key]);

    $retry->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($retry->json())->toBe($first->json())->and($this->calls)->toBe(1);
});

it('refuses the same key with a different body', function () {
    $key = (string) Str::uuid7();
    $this->postJson('/api/v1/_test/orders', ['item' => 'a'], ['Idempotency-Key' => $key])->assertCreated();

    $this->postJson('/api/v1/_test/orders', ['item' => 'b'], ['Idempotency-Key' => $key])
        ->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    expect($this->calls)->toBe(1);
});

it('answers 409 while the first request with the key is still running', function () {
    $key = (string) Str::uuid7();
    $this->postJson('/api/v1/_test/orders', ['item' => 'a'], ['Idempotency-Key' => $key]);
    DB::table('idempotency_keys')->update(['status' => 'IN_PROGRESS', 'response_body_encrypted' => null]);

    $this->postJson('/api/v1/_test/orders', ['item' => 'a'], ['Idempotency-Key' => $key])
        ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_IN_PROGRESS');
});

it('releases the key after a server error so the client can retry', function () {
    $key = (string) Str::uuid7();
    $this->postJson('/api/v1/_test/broken', [], ['Idempotency-Key' => $key])->assertStatus(500);

    expect(DB::table('idempotency_keys')->count())->toBe(0);
});

it('stores the response encrypted and keeps keys apart per caller', function () {
    $key = (string) Str::uuid7();
    $this->postJson('/api/v1/_test/orders', ['item' => 'secret-item'], ['Idempotency-Key' => $key, 'REMOTE_ADDR' => '10.0.0.1']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/api/v1/_test/orders', ['item' => 'secret-item'], ['Idempotency-Key' => $key])->assertCreated();

    expect(json_encode(DB::table('idempotency_keys')->get()))->not->toContain('secret-item')
        ->and($this->calls)->toBe(2);
});
