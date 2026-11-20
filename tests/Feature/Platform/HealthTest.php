<?php

it('answers liveness without revealing anything', function () {
    $this->getJson('/api/v1/health')->assertOk()->assertExactJson([
        'success' => true, 'data' => ['status' => 'ok'], 'meta' => [], 'message' => null,
    ]);
});

it('reports readiness of database, redis and migrations to internal callers', function () {
    $this->getJson('/api/v1/ready')->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.checks', ['database' => 'ok', 'redis' => 'ok', 'migrations' => 'ok', 'search' => 'ok']);
});

it('hides readiness details from outside networks', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/api/v1/ready')->assertNotFound();
});

it('reports not ready when a dependency is down', function () {
    config(['database.redis.default.host' => 'nowhere.invalid', 'database.redis.default.port' => 1]);
    app('redis')->purge();

    $this->getJson('/api/v1/ready')->assertStatus(503)->assertJsonPath('data.checks.redis', 'fail');
});
