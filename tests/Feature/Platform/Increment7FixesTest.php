<?php

use App\Modules\Administration\Contracts\Settings;
use App\Modules\Rbac\Enums\Role;
use App\Shared\Exceptions\InvalidValue;
use App\Shared\Http\Middleware\EnsureIdempotency;
use App\Shared\Jobs\DispatchOutbox;
use App\Shared\Services\Outbox;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\TestDomainEvent;

/*
 * Regression tests for the increment 7 review findings.
 */

it('lets a retry take over a key abandoned by a crashed request (finding 1)', function () {
    $calls = 0;
    Route::middleware(['api', 'idempotent'])->post('/api/v1/_test/pay', function () use (&$calls) {
        $calls++;

        return response()->json(['ok' => true], 201);
    });
    $key = (string) Str::uuid7();

    // Simulate a request that died after claiming the key.
    $this->postJson('/api/v1/_test/pay', [], ['Idempotency-Key' => $key]);
    DB::table('idempotency_keys')->update(['status' => 'IN_PROGRESS', 'response_body_encrypted' => null]);

    $this->postJson('/api/v1/_test/pay', [], ['Idempotency-Key' => $key])->assertStatus(409);

    $this->travel(EnsureIdempotency::ABANDONED_AFTER_MINUTES + 1)->minutes();
    $this->postJson('/api/v1/_test/pay', [], ['Idempotency-Key' => $key])->assertCreated()->assertHeaderMissing('Idempotency-Replayed');

    expect($calls)->toBe(2)->and(DB::table('idempotency_keys')->value('status'))->toBe('COMPLETED');
});

it('parks an outbox event after the maximum attempts and can retry it (finding 2)', function () {
    Bus::fake([DispatchOutbox::class]);
    config(['outbox.events' => ['tests.something_happened' => TestDomainEvent::class], 'outbox.max_attempts' => 3]);
    $fail = true;
    Event::listen(TestDomainEvent::class, function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('listener down');
        }
    });

    app(Outbox::class)->record(new TestDomainEvent('poison', 'x'));
    foreach (range(1, 4) as $_) {
        DB::table('outbox_events')->whereNull('failed_at')->update(['available_at' => now()->subSecond()]);
        app(Outbox::class)->relay();
    }

    $row = DB::table('outbox_events')->first();
    expect($row->attempts)->toBe(3)->and($row->failed_at)->not->toBeNull()->and($row->dispatched_at)->toBeNull();

    $fail = false;
    Artisan::call('outbox:retry');
    app(Outbox::class)->relay();

    expect(DB::table('outbox_events')->value('dispatched_at'))->not->toBeNull();
});

it('records the real step-up state of a SUPER_ADMIN action (finding 3)', function () {
    seed_roles();
    fake_messages();
    Route::middleware(['api', 'auth:sanctum', 'signin.valid', 'mfa.satisfied', 'step-up'])
        ->post('/api/v1/_test/admin-action', fn () => response()->json(['ok' => true]));

    $superAdmin = account(Role::SuperAdmin);
    [$secret, , $access] = enroll_mfa($superAdmin);
    $this->travel(31)->seconds();
    $this->postJson('/api/v1/auth/mfa/step-up', ['code' => current_totp($secret)], bearer($access))->assertOk();
    $this->postJson('/api/v1/_test/admin-action', [], bearer($access))->assertOk();

    // The log is append-only, so read the newest rows rather than clearing it first.
    $latest = DB::table('admin_activity_logs')->orderByDesc('id')->limit(2)->pluck('step_up_verified', 'path');

    expect($latest['/api/v1/_test/admin-action'])->toBeTrue()
        ->and($latest['/api/v1/auth/mfa/step-up'])->toBeFalse();
});

it('releases the relay lock as soon as the relay starts (finding 4)', function () {
    expect(new DispatchOutbox)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and(in_array(ShouldBeUnique::class, class_implements(DispatchOutbox::class), true))->toBeTrue();
});

it('allows harmless keys containing "token" but refuses secret names (finding 5)', function () {
    $settings = app(Settings::class);
    $settings->set('auth.access_token_minutes', 15);

    expect($settings->get('auth.access_token_minutes'))->toBe(15)
        ->and(fn () => $settings->set('auth.refresh_token', 'x'))->toThrow(InvalidValue::class)
        ->and(fn () => $settings->set('sms.provider_api_key', 'x'))->toThrow(InvalidValue::class);
});

it('replays an empty 204 response as empty (finding 6)', function () {
    Route::middleware(['api', 'idempotent'])->delete('/api/v1/_test/thing', fn () => response()->noContent());
    $key = (string) Str::uuid7();

    $this->deleteJson('/api/v1/_test/thing', [], ['Idempotency-Key' => $key])->assertNoContent();
    $replay = $this->deleteJson('/api/v1/_test/thing', [], ['Idempotency-Key' => $key]);

    $replay->assertNoContent()->assertHeader('Idempotency-Replayed', 'true');
    expect($replay->getContent())->toBe('');
});

it('keeps the response of a SUPER_ADMIN action when its activity log cannot be written (finding 7)', function () {
    seed_roles();
    fake_messages();
    $token = native_login(account(Role::SuperAdmin))->json('data.tokens.access_token');
    DB::statement('CREATE TEMP TABLE admin_activity_logs (id int)'); // shadows the real table: inserts now fail

    $this->postJson('/api/v1/auth/logout', [], bearer($token))->assertOk();
});
