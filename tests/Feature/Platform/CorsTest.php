<?php

/*
 * Browser apps use cookie sessions (D-04), so CORS must allow credentials for
 * the first-party origins only.
 */

it('allows credentialed requests from a first-party app origin', function () {
    $this->call('OPTIONS', '/api/v1/auth/login', server: [
        'HTTP_ORIGIN' => 'http://localhost:3001',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
    ])
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3001')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

it('gives no CORS permission to other origins', function () {
    $response = $this->call('OPTIONS', '/api/v1/auth/login', server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example')
        ->and($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('*');
});

it('exposes the correlation id to browser code', function () {
    $this->getJson('/api/v1/health', ['Origin' => 'http://localhost:3001'])
        ->assertHeader('Access-Control-Expose-Headers', 'X-Request-Id, Idempotency-Replayed, Retry-After');
});

it('serves the CSRF cookie endpoint for cookie clients', function () {
    $this->get('/sanctum/csrf-cookie', ['Origin' => 'http://localhost:3001', 'Referer' => 'http://localhost:3001/'])
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});
