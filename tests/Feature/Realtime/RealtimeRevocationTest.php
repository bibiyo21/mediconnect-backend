<?php

use App\Modules\Authentication\Contracts\RevokesSessions;
use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Notifications\Contracts\TerminatesRealtimeSessions;
use App\Modules\Notifications\Jobs\TerminateRealtimeConnections;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Increment 5.4b, M-3: a connection must not outlive the authorization that allowed it. Each event
 * that ends an authorization asks Reverb (through the Pusher client) to terminate that user's
 * connections, once, after the transaction commits and never on a rollback.
 *
 * These tests use a FakePusher: they check WHEN and HOW OFTEN the terminate call is made, not that a
 * socket really closes. That is proven against a real Reverb by the local script
 * infrastructure/scripts/reverb-terminate-check.mjs (see infrastructure/README.md).
 */

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
    $this->pusher = fake_pusher();
});

/** A privileged session with MFA and a fresh step-up (suspension and role changes need one). */
function revocation_admin(Role $role = Role::Admin): array
{
    app('auth')->forgetGuards();
    $admin = account($role);
    [$secret, , $access] = enroll_mfa($admin);
    test()->travel(31)->seconds();
    test()->postJson('/api/v1/auth/mfa/step-up', ['code' => current_totp($secret)], bearer($access))->assertOk();
    app('auth')->forgetGuards();

    return [$admin, bearer($access)];
}

it('terminates the user\'s connections once on logout (token with a device)', function () {
    $user = patient_account();
    $login = native_login($user)->json('data');
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/auth/logout', [], bearer($login['tokens']['access_token']))->assertOk();

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('terminates once on logout of a web session', function () {
    $user = patient_account();
    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => TEST_PASSWORD])->assertOk();
    $this->pusher->terminated = [];

    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/auth/logout')->assertOk();

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('terminates once when a device is revoked', function () {
    $user = patient_account();
    $current = native_login($user)->json('data');
    $other = native_login($user)->json('data');
    app('auth')->forgetGuards();

    $this->deleteJson('/api/v1/auth/devices/'.$other['device_id'], [], bearer($current['tokens']['access_token']))->assertOk();

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('does not terminate anything when someone else\'s device is not found', function () {
    $victim = native_login(patient_account())->json('data');
    $attacker = native_login(patient_account())->json('data');
    $this->pusher->terminated = [];
    app('auth')->forgetGuards();

    $this->deleteJson('/api/v1/auth/devices/'.$victim['device_id'], [], bearer($attacker['tokens']['access_token']))->assertStatus(404);

    expect($this->pusher->terminated)->toBe([]);
});

it('terminates once when an account is suspended (all devices and tokens, one call)', function () {
    [, $headers] = revocation_admin();
    $target = patient_account();
    native_login($target);
    native_login($target);
    $this->pusher->terminated = [];
    app('auth')->forgetGuards();

    $this->postJson("/api/v1/admin/users/{$target->id}/suspend", ['reason' => 'Reported abuse, ticket 123'], $headers)->assertOk();

    expect($this->pusher->terminated)->toBe([$target->id]);
});

it('terminates once when an account is deactivated', function () {
    $user = patient_account();

    app(Accounts::class)->anonymize($user);

    expect($this->pusher->terminated)->toBe([$user->id])
        ->and($user->refresh()->status->value)->toBe('DEACTIVATED');
});

it('terminates once when a clinic membership is removed, for the affected user only', function () {
    [$clinic, , $managerHeaders] = clinic_with_manager();
    [$staff] = clinic_member($clinic, Role::ClinicStaff);
    $memberId = ClinicMember::where('user_id', $staff->id)->value('id');
    app('auth')->forgetGuards();

    $this->deleteJson("/api/v1/clinics/{$clinic}/members/{$memberId}", [], $managerHeaders)->assertOk();

    expect($this->pusher->terminated)->toBe([$staff->id]);
});

it('terminates once when a SUPER_ADMIN removes a role, and not when only adding one', function () {
    [, $headers] = revocation_admin(Role::SuperAdmin);
    $target = account(Role::Patient);
    app('auth')->forgetGuards();

    $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['PATIENT', 'CLINIC_STAFF'], 'reason' => 'Extra duty'], $headers)->assertOk();
    expect($this->pusher->terminated)->toBe([]);

    app('auth')->forgetGuards();
    $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['PATIENT'], 'reason' => 'Duty ended'], $headers)->assertOk();
    expect($this->pusher->terminated)->toBe([$target->id]);
});

it('revokeEverything terminates once however many devices there are', function () {
    $user = patient_account();
    native_login($user);
    native_login($user);

    app(RevokesSessions::class)->revokeEverything($user);

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('terminates only after the transaction commits, and never when it rolls back', function () {
    $realtime = app(TerminatesRealtimeSessions::class);

    try {
        DB::transaction(function () use ($realtime) {
            $realtime->terminateForUser('018f1c3e-7b1a-7000-8000-000000000001', 'test');
            expect($this->pusher->terminated)->toBe([]); // still inside the transaction
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }
    expect($this->pusher->terminated)->toBe([]);

    DB::transaction(fn () => $realtime->terminateForUser('018f1c3e-7b1a-7000-8000-000000000002', 'test'));
    expect($this->pusher->terminated)->toBe(['018f1c3e-7b1a-7000-8000-000000000002']);
});

it('does not terminate when the revoking request rolls back', function () {
    $user = patient_account();
    native_login($user);

    try {
        DB::transaction(function () use ($user) {
            app(RevokesSessions::class)->revokeEverything($user);
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect($this->pusher->terminated)->toBe([]);
});

it('queues the termination on the critical queue with retries, backoff and a timeout above the HTTP timeout', function () {
    Queue::fake();

    app(TerminatesRealtimeSessions::class)->terminateForUser('018f1c3e-7b1a-7000-8000-000000000001', 'test');

    Queue::assertPushedOn('critical', TerminateRealtimeConnections::class, fn (TerminateRealtimeConnections $job) => $job->tries === 4 && $job->backoff() === [2, 10, 30]
        && $job->timeout > config('broadcasting.connections.reverb.client_options.timeout'));
});

it('queues nothing and calls nothing when the driver is not reverb or pusher', function (string $driver) {
    Queue::fake();
    config(['broadcasting.default' => $driver]);

    app(TerminatesRealtimeSessions::class)->terminateForUser('018f1c3e-7b1a-7000-8000-000000000001', 'test');
    DB::transaction(fn () => app(TerminatesRealtimeSessions::class)->terminateForUser('018f1c3e-7b1a-7000-8000-000000000001', 'test'));

    Queue::assertNothingPushed();
    expect($this->pusher->terminated)->toBe([]);
})->with(['null', 'log']);

it('swallows a Reverb failure, logs ids only, records a HIGH security event once, and the request still succeeds', function () {
    $this->pusher->failWith = new RuntimeException('connection refused to http://secret-host');
    Log::spy();
    $user = patient_account();
    $login = native_login($user)->json('data');
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/auth/logout', [], bearer($login['tokens']['access_token']))->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'realtime.terminate_failed'
        && $context['user_id'] === $user->id
        && $context['reason'] === 'device_revoked'
        && $context['exception'] === RuntimeException::class
        && ! str_contains(json_encode($context), 'secret-host'))->atLeast()->once();
    expect(DB::table('user_devices')->where('id', $login['device_id'])->value('revoked_at'))->not->toBeNull();

    // Not silent: one HIGH row with ids and the exception class, never the message.
    $rows = DB::table('security_events')->where('event_type', 'REALTIME_TERMINATION_FAILED')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->severity)->toBe('HIGH')
        ->and($rows[0]->user_id)->toBe($user->id)
        ->and(json_decode($rows[0]->metadata, true))->toBe(['reason' => 'device_revoked', 'exception' => RuntimeException::class])
        ->and(json_encode($rows[0]))->not->toContain('secret-host');
});

it('records the failure when dispatching itself fails', function () {
    config(['queue.default' => 'not-configured']); // dispatching now throws
    $user = patient_account();

    app(TerminatesRealtimeSessions::class)->terminateForUser($user->id, 'logout');

    expect(DB::table('security_events')->where('event_type', 'REALTIME_TERMINATION_FAILED')->where('user_id', $user->id)->count())->toBe(1);
});

/*
 * Call sites that end an authorization: each terminates once.
 */

it('terminates once when a contact change signs out the other devices', function () {
    $user = patient_account();
    $headers = signed_in($user);
    native_login($user); // another device
    $this->pusher->terminated = [];
    app('auth')->forgetGuards();

    $challenge = $this->postJson('/api/v1/auth/contact/otp', ['email' => 'new.address@example.com', 'current_password' => TEST_PASSWORD], $headers)->assertStatus(202)->json('data.challenge_id');
    $code = $this->messages->lastCodeFor('new.address@example.com');
    $token = $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $code])->assertOk()->json('data.verification_token');
    $this->pusher->terminated = [];
    $this->putJson('/api/v1/auth/contact', ['email' => 'new.address@example.com', 'verification_token' => $token], $headers)->assertOk();

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('terminates once when a password reset signs out every device', function () {
    $user = account(Role::Patient);
    native_login($user);
    native_login($user);
    $this->pusher->terminated = [];

    $challenge = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202)->json('data.challenge_id');
    $token = $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $this->messages->lastCodeFor($user->email)])->assertOk()->json('data.verification_token');
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email, 'verification_token' => $token,
        'password' => 'Brand-new-pass-77', 'password_confirmation' => 'Brand-new-pass-77',
    ])->assertOk();

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('terminates once when the account holder requests deletion', function () {
    $user = patient_account();
    $headers = signed_in($user);
    native_login($user);
    $this->pusher->terminated = [];
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/auth/account/deletion-request', ['current_password' => TEST_PASSWORD], $headers)->assertStatus(202);

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('terminates when a replayed refresh token revokes the device', function () {
    $user = account(Role::Patient);
    $first = native_login($user)->json('data');
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['tokens']['refresh_token']])->assertOk();
    $this->pusher->terminated = [];
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['tokens']['refresh_token']])->assertStatus(401);

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('cuts the connections only after the web session is gone, so a reconnect cannot authorize again', function () {
    $user = patient_account();
    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => TEST_PASSWORD])->assertOk();
    $this->pusher->terminated = [];

    // Observe the session at the moment the terminate call is made: its device marker is flushed by invalidate().
    $spy = new class implements TerminatesRealtimeSessions
    {
        /** @var list<bool> */
        public array $sessionStillHadDevice = [];

        public function terminateForUser(string $userId, string $reason): void
        {
            $this->sessionStillHadDevice[] = app('session.store')->get('auth.device_id') !== null;
        }
    };
    $this->app->instance(TerminatesRealtimeSessions::class, $spy);

    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/auth/logout')->assertOk();

    expect($spy->sessionStillHadDevice)->toBe([false]);
});

/*
 * L-6: a denied channel authorization is a security event.
 */

it('records REALTIME_SUBSCRIPTION_DENIED once per user, channel and minute, with no payload', function () {
    $user = patient_account();
    $headers = signed_in($user);
    $other = (string) Str::uuid7();

    foreach (range(1, 3) as $_) {
        channel_auth("presence-user.{$other}", $headers)->assertForbidden();
    }

    $rows = DB::table('security_events')->where('event_type', 'REALTIME_SUBSCRIPTION_DENIED')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->user_id)->toBe($user->id)
        ->and($rows[0]->severity)->toBe('WARNING')
        ->and($rows[0]->ip_hash)->not->toBeNull()
        ->and(json_decode($rows[0]->metadata, true))->toBe(['channel' => "presence-user.{$other}"]);

    // A different channel is a separate row; the same channel again after a minute is too.
    channel_auth("presence-clinic.{$other}.queue", $headers)->assertForbidden();
    expect(DB::table('security_events')->where('event_type', 'REALTIME_SUBSCRIPTION_DENIED')->count())->toBe(2);

    $this->travel(61)->seconds();
    channel_auth("presence-user.{$other}", $headers)->assertForbidden();
    expect(DB::table('security_events')->where('event_type', 'REALTIME_SUBSCRIPTION_DENIED')->count())->toBe(3);
});

it('records an unknown channel as a denial but writes nothing for an allowed subscription', function () {
    $user = patient_account();
    $headers = signed_in($user);

    channel_auth("presence-user.{$user->id}", $headers)->assertOk();
    expect(DB::table('security_events')->where('event_type', 'REALTIME_SUBSCRIPTION_DENIED')->count())->toBe(0);

    channel_auth('presence-billing.everything', $headers)->assertForbidden();
    expect(DB::table('security_events')->where('event_type', 'REALTIME_SUBSCRIPTION_DENIED')->count())->toBe(1);
});

it('does not record anything for an anonymous caller or an invalid channel name', function () {
    channel_auth('presence-user.'.Str::uuid7())->assertStatus(401);
    $headers = signed_in(patient_account());
    channel_auth('presence-user.a b', $headers)->assertStatus(422);

    expect(DB::table('security_events')->where('event_type', 'REALTIME_SUBSCRIPTION_DENIED')->count())->toBe(0);
});

/*
 * L-7: a clinic membership restricted to a location must name a location of its own clinic.
 */

it('rejects a clinic member row that names a location of another clinic', function () {
    [$clinic] = clinic_with_manager('Santos Family Clinic');
    [, $otherLocation] = clinic_with_manager('Diliman Medical Clinic');
    $user = account(Role::ClinicStaff);
    $row = fn (?string $locationId) => DB::table('clinic_members')->insert([
        'id' => (string) Str::uuid7(), 'clinic_id' => $clinic, 'clinic_location_id' => $locationId, 'user_id' => $user->id,
        'member_role' => 'CLINIC_STAFF', 'status' => 'ACTIVE', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => $row($otherLocation))->toThrow(QueryException::class, 'clinic_members_location_in_clinic_fk');
});

it('still accepts a clinic-wide membership and a location of the same clinic', function () {
    [$clinic, $location] = clinic_with_manager('Santos Family Clinic');

    clinic_member($clinic, Role::ClinicStaff);
    clinic_member($clinic, Role::ClinicStaff, $location);

    expect(ClinicMember::where('clinic_id', $clinic)->count())->toBe(3);
});
