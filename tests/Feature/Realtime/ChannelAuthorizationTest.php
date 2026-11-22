<?php

use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/*
 * Increment 5.2: every channel subscription is authorized server-side through
 * POST /api/v1/broadcasting/auth (rbac-permissions.md §8). The matrix below
 * runs every actor against every channel and compares the whole table, so a
 * change in who may subscribe shows as a diff of statuses.
 *
 * 200 = signed, 403 = refused, 401 = not signed in.
 */

beforeEach(function () {
    seed_roles();
    use_signing_broadcaster();

    // Clinic 1 with two locations; the manager belongs to the whole clinic.
    [$this->clinic, $this->locA, $manager] = clinic_with_manager('Santos Family Clinic');
    $this->locB = add_location($this->clinic, $manager, 'Branch B');
    // A clinic the actors above have nothing to do with.
    [$this->otherClinic, $this->otherLoc, $otherManager] = clinic_with_manager('Diliman Medical Clinic');

    [$this->doctor, $doctorHeaders] = verified_doctor('0123456', 'ACTIVE');
    [$this->otherDoctor, $otherDoctorHeaders] = verified_doctor('7654321', 'ACTIVE');

    $patient = patient_account();
    $finance = account(Role::FinanceOfficer);
    $support = account(Role::SupportAgent);
    $admin = account(Role::Admin);
    $superAdmin = account(Role::SuperAdmin);

    $this->users = [
        'patient' => $patient,
        'doctor' => User::findOrFail($this->doctor->user_id),
        'other_doctor' => User::findOrFail($this->otherDoctor->user_id),
        'finance' => $finance,
        'support' => $support,
        'admin' => $admin,
        'super_admin' => $superAdmin,
    ];
    $this->headers = [
        'anonymous' => [],
        'patient' => signed_in($patient),
        'doctor' => $doctorHeaders,
        'other_doctor' => $otherDoctorHeaders,
        'finance' => session_for($finance),
        'support' => session_for($support),
        'admin' => session_for($admin),
        'super_admin' => session_for($superAdmin),
        'manager_all' => $manager,
        'other_manager' => $otherManager,
    ];
    foreach ([
        'staff_all' => [Role::ClinicStaff, null],
        'staff_a' => [Role::ClinicStaff, $this->locA],
        'staff_b' => [Role::ClinicStaff, $this->locB],
        'manager_a' => [Role::ClinicManager, $this->locA],
    ] as $name => [$role, $location]) {
        [$this->users[$name], $this->headers[$name]] = clinic_member($this->clinic, $role, $location);
    }
    $this->users['manager_all'] = User::whereKey(
        DB::table('clinic_members')->where('clinic_id', $this->clinic)->whereNull('clinic_location_id')->where('member_role', 'CLINIC_MANAGER')->value('user_id')
    )->firstOrFail();
    $this->users['other_manager'] = User::whereKey(
        DB::table('clinic_members')->where('clinic_id', $this->otherClinic)->value('user_id')
    )->firstOrFail();
    app('auth')->forgetGuards();
});

/** @return array<string, int> actor => HTTP status for one channel */
function matrix_for(string $channel, array $headers): array
{
    return collect($headers)->map(fn (array $h) => channel_auth($channel, $h)->status())->all();
}

it('lets each person join their own user channel and nobody else\'s', function () {
    foreach ($this->users as $name => $user) {
        channel_auth("private-user.{$user->id}", $this->headers[$name])->assertOk()->assertJsonStructure(['auth']);
    }

    $patientId = $this->users['patient']->id;
    $expected = collect($this->headers)->map(fn ($h, $name) => match ($name) {
        'patient' => 200,
        'anonymous' => 401,
        default => 403,
    })->all();

    expect(matrix_for("private-user.{$patientId}", $this->headers))->toBe($expected);
});

it('signs with the Pusher protocol, not the API envelope', function () {
    $response = channel_auth('private-user.'.$this->users['patient']->id, $this->headers['patient'])->assertOk();

    expect($response->json())->toHaveKey('auth')->not->toHaveKey('success')
        ->and($response->json('auth'))->toStartWith(REALTIME_TEST_KEY.':');
});

it('limits a doctor channel to the owner of that doctor profile', function () {
    $expected = collect($this->headers)->map(fn ($h, $name) => match ($name) {
        'doctor' => 200,
        'anonymous' => 401,
        default => 403, // another doctor, clinic managers and staff, finance, support and admins
    })->all();

    expect(matrix_for("private-doctor.{$this->doctor->id}", $this->headers))->toBe($expected);
});

it('limits a clinic location channel to members with presence.manage at that location, never global roles', function () {
    // Admins and support read clinic data through the audited REST API, not through subscriptions.
    $atA = ['anonymous' => 401, 'patient' => 403, 'doctor' => 403, 'other_doctor' => 403, 'finance' => 403, 'support' => 403,
        'admin' => 403, 'super_admin' => 403, 'manager_all' => 200, 'other_manager' => 403,
        'staff_all' => 200, 'staff_a' => 200, 'staff_b' => 403, 'manager_a' => 200];
    $atB = [...$atA, 'staff_a' => 403, 'staff_b' => 200, 'manager_a' => 403];

    expect(matrix_for("private-clinic.{$this->locA}", $this->headers))->toBe($atA)
        ->and(matrix_for("private-clinic.{$this->locB}", $this->headers))->toBe($atB);
});

it('limits a queue channel to members with walkins.view at that location, never global roles', function () {
    $atA = ['anonymous' => 401, 'patient' => 403, 'doctor' => 403, 'other_doctor' => 403, 'finance' => 403, 'support' => 403,
        'admin' => 403, 'super_admin' => 403, 'manager_all' => 200, 'other_manager' => 403,
        'staff_all' => 200, 'staff_a' => 200, 'staff_b' => 403, 'manager_a' => 200];
    $atB = [...$atA, 'staff_a' => 403, 'staff_b' => 200, 'manager_a' => 403];

    expect(matrix_for("private-clinic.{$this->locA}.queue", $this->headers))->toBe($atA)
        ->and(matrix_for("private-clinic.{$this->locB}.queue", $this->headers))->toBe($atB)
        ->and(matrix_for("private-clinic.{$this->otherLoc}.queue", $this->headers)['manager_all'])->toBe(403);
});

it('refuses a member without walkins.view on the queue channel but not on the location channel', function () {
    SpatieRole::findByName('CLINIC_STAFF', 'web')->revokePermissionTo('walkins.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    channel_auth("private-clinic.{$this->locA}.queue", $this->headers['staff_all'])->assertForbidden();
    channel_auth("private-clinic.{$this->locA}", $this->headers['staff_all'])->assertOk();
});

it('refuses removed members and members of the wrong role', function () {
    [, $removedHeaders] = clinic_member($this->clinic, Role::ClinicStaff, null, 'REMOVED');
    channel_auth("private-clinic.{$this->locA}.queue", $removedHeaders)->assertForbidden();
    channel_auth("private-clinic.{$this->locA}", $removedHeaders)->assertForbidden();

    // A clinic_members row is not enough without the matching role.
    $doctorWithRow = $this->users['doctor'];
    (new ClinicMember)->forceFill(['clinic_id' => $this->clinic, 'user_id' => $doctorWithRow->id, 'member_role' => 'CLINIC_STAFF', 'status' => 'ACTIVE', 'joined_at' => now()])->save();
    channel_auth("private-clinic.{$this->locA}.queue", $this->headers['doctor'])->assertForbidden();
});

it('refuses malformed, unknown and unprefixed channels without a server error', function (string $channel, int $status) {
    $channel = str_replace(['LOCA', 'USER'], [$this->locA, $this->users['manager_all']->id], $channel);
    channel_auth($channel, $this->headers['manager_all'])->assertStatus($status);
})->with([
    'not a uuid' => ['private-clinic.not-a-uuid', 403],
    'not a uuid on queue' => ['private-clinic.1.queue', 403],
    'sql-ish id (rejected by validation)' => ["private-doctor.x'--", 422],
    'a location that does not exist' => ['private-clinic.018f1c3e-7b1a-7000-8000-000000000000.queue', 403],
    'unknown channel' => ['private-admin.secrets', 403],
    'presence channels are not offered (a real location)' => ['presence-clinic.LOCA', 422],
    'presence channel for a user' => ['presence-user.USER', 422],
    'encrypted private channel for the caller\'s own user' => ['private-encrypted-user.USER', 422],
    'encrypted private channel for a clinic' => ['private-encrypted-clinic.LOCA.queue', 422],
    'public-style name (never authorized here)' => ['doctor-availability.018f1c3e-7b1a-7000-8000-000000000000', 422],
    'a channel with a space' => ['private-user.a b', 422],
]);

it('validates the handshake body', function () {
    $headers = $this->headers['patient'];
    $this->postJson('/api/v1/broadcasting/auth', ['channel_name' => 'private-user.x'], $headers)->assertStatus(422)->assertJsonValidationErrors('socket_id');
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => 'abc', 'channel_name' => 'private-user.x'], $headers)->assertStatus(422)->assertJsonValidationErrors('socket_id');
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.2'], $headers)->assertStatus(422)->assertJsonValidationErrors('channel_name');
    $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => ['private-user.x']], $headers)->assertStatus(422);
});

it('answers unauthenticated callers with the 401 envelope, never a signature', function () {
    channel_auth('private-user.'.$this->users['patient']->id)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED')->assertJsonMissingPath('auth');
    $this->getJson('/api/v1/broadcasting/auth')->assertStatus(405); // POST only
});

it('works with a first-party cookie session as well as a bearer token', function () {
    $patient = $this->users['patient'];
    app('auth')->forgetGuards();
    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/auth/login', ['identifier' => $patient->email, 'password' => TEST_PASSWORD])->assertOk();

    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => "private-user.{$patient->id}"])->assertOk()->assertJsonStructure(['auth']);
    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-user.'.$this->users['doctor']->id])->assertForbidden();
});

it('applies an IP limit before authentication too', function () {
    // The route carries throttle:120,1 ahead of auth:sanctum.
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/broadcasting/auth');
    expect($route->gatherMiddleware())->toContain('throttle:120,1', 'throttle:broadcast-auth')
        ->and(array_search('throttle:120,1', $route->gatherMiddleware()))->toBeLessThan(array_search('auth:sanctum', $route->gatherMiddleware()));
});

it('refuses a sign-in that still owes MFA and a suspended account', function () {
    $limited = bearer(native_login(account(Role::Admin))->json('data.tokens.access_token')); // admin who has not enrolled MFA yet
    channel_auth("private-clinic.{$this->locA}", $limited)->assertUnauthorized()->assertJsonPath('code', 'MFA_REQUIRED');

    User::whereKey($this->users['patient']->id)->update(['status' => 'SUSPENDED']);
    channel_auth('private-user.'.$this->users['patient']->id, $this->headers['patient'])->assertForbidden();
});

it('rate limits handshakes per user with the named limiter', function () {
    RateLimiter::clear('broadcast-auth:'.$this->users['patient']->id);
    $channel = 'private-user.'.$this->users['patient']->id;

    foreach (range(1, 60) as $i) {
        channel_auth($channel, $this->headers['patient'])->assertOk();
    }
    channel_auth($channel, $this->headers['patient'])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');

    // Another user is not affected.
    channel_auth('private-user.'.$this->users['doctor']->id, $this->headers['doctor'])->assertOk();
});
