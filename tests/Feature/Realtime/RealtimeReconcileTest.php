<?php

use App\Modules\Audit\Contracts\RecordsSecurityEvents;
use App\Modules\Audit\Enums\SecurityEventType;
use App\Modules\Audit\Enums\Severity;
use App\Modules\Authentication\Contracts\LiveSessions;
use App\Modules\Authentication\Services\SessionIssuer;
use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Notifications\Exceptions\RealtimeReconcileFailedException;
use App\Modules\Notifications\Exceptions\RealtimeTerminationFailedException;
use App\Modules\Notifications\Realtime\ChannelAuthorizer;
use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\RealtimeConfigGuard;
use App\Modules\Notifications\Services\RealtimeReconciler;
use App\Modules\Notifications\Services\RealtimeSessions;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Pusher\ApiErrorException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/*
 * Increment 5.6-0: the realtime reconciliation sweep (`php artisan realtime:reconcile`). A FakePusher
 * stands in for Reverb's signed HTTP API, so these tests check WHO gets terminated and WHEN, and that
 * an infrastructure error never closes anybody. That a real socket closes is proven by
 * infrastructure/scripts/reverb-terminate-check.mjs.
 */

beforeEach(function () {
    seed_roles();
    $this->pusher = fake_pusher();
    Cache::flush();
    [$this->clinic, $this->locA, $manager] = clinic_with_manager('Santos Family Clinic');
    $this->locB = add_location($this->clinic, $manager, 'Branch B');
});

/** Signs the user in natively, so they hold a live access token and refresh token. */
function swept(User $user): User
{
    native_login($user)->assertOk();
    app('auth')->forgetGuards();

    return $user;
}

function sweep(): array
{
    return app(RealtimeReconciler::class)->run();
}

function put_on(string $channel, string ...$userIds): void
{
    $name = "presence-{$channel}";
    test()->pusher->channels[$name] = count($userIds);
    test()->pusher->members[$name] = array_values($userIds);
}

function staff_at(?string $locationId = null, string $status = 'ACTIVE'): User
{
    [$user] = clinic_member(test()->clinic, Role::ClinicStaff, $locationId, $status);

    return swept($user);
}

it('keeps an authorized member on every channel', function () {
    $staff = staff_at();
    $doctor = verified_doctor('0123456', 'ACTIVE')[0];
    $doctorUser = swept(User::findOrFail($doctor->user_id));
    put_on("clinic.{$this->locA}", $staff->id);
    put_on("clinic.{$this->locA}.queue", $staff->id);
    put_on("user.{$staff->id}", $staff->id);
    put_on("doctor.{$doctor->id}", $doctorUser->id);

    $stats = sweep();

    expect($this->pusher->terminated)->toBe([])->and($stats['terminated'])->toBe(0)->and($stats['channels'])->toBe(4);
});

it('terminates a member removed without any terminate call, within one run', function () {
    $staff = staff_at();
    put_on("clinic.{$this->locA}", $staff->id);
    ClinicMember::where('user_id', $staff->id)->update(['status' => 'REMOVED', 'removed_at' => now()]);

    sweep();

    expect($this->pusher->terminated)->toBe([$staff->id]);
    $event = DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_REVOKED')->first();
    expect($event->severity)->toBe('WARNING')->and($event->user_id)->toBe($staff->id)
        ->and(json_decode($event->metadata, true))->toBe(['reason' => 'reconcile_clinic']);
});

it('terminates a suspended user whose devices were never revoked', function () {
    $user = patient_account();
    swept($user);
    put_on("user.{$user->id}", $user->id);
    DB::table('users')->where('id', $user->id)->update(['status' => 'SUSPENDED']);

    sweep();

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('terminates a member who lost walkins.view, even from a stale permission cache', function () {
    $staff = staff_at();
    put_on("clinic.{$this->locA}.queue", $staff->id);
    expect(sweep()['terminated'])->toBe(0); // loads and caches the permissions
    SpatieRole::findByName(Role::ClinicStaff->value, config('rbac.guard'))->revokePermissionTo('walkins.view');
    // No forgetCachedPermissions() here on purpose: the sweep resets it at the start of the run.

    sweep();

    expect($this->pusher->terminated)->toBe([$staff->id]);
});

it('terminates the old owner when a doctor profile changes hands', function () {
    [$doctor] = verified_doctor('0123456', 'ACTIVE');
    $oldOwner = swept(User::findOrFail($doctor->user_id));
    put_on("doctor.{$doctor->id}", $oldOwner->id);
    $newOwner = patient_account();
    DoctorProfile::whereKey($doctor->id)->update(['user_id' => $newOwner->id]);

    sweep();

    expect($this->pusher->terminated)->toBe([$oldOwner->id]);
});

it('terminates location-restricted staff who sit on another location\'s channel', function () {
    $staff = staff_at($this->locB);
    put_on("clinic.{$this->locA}", $staff->id);
    put_on("clinic.{$this->locB}", $staff->id);

    sweep();

    expect($this->pusher->terminated)->toBe([$staff->id]);
});

it('terminates a user with no live credential', function () {
    $user = patient_account(); // never signed in: no token, no device
    put_on("user.{$user->id}", $user->id);

    sweep();

    expect($this->pusher->terminated)->toBe([$user->id]);
    expect(DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_REVOKED')->value('metadata'))->toContain('reconcile_no_credential');
});

it('terminates a user whose every device was revoked', function () {
    $user = patient_account();
    $login = native_login($user)->json('data');
    DB::table('user_devices')->where('id', $login['device_id'])->update(['revoked_at' => now()]);
    put_on("user.{$user->id}", $user->id);

    sweep();

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('keeps a user whose access token expired but whose refresh token is still valid', function () {
    $user = patient_account();
    native_login($user)->assertOk();
    put_on("user.{$user->id}", $user->id);
    $this->travel(20)->minutes(); // access tokens last 15 minutes, refresh tokens 30 days

    sweep();

    expect($this->pusher->terminated)->toBe([]);
    expect(app(LiveSessions::class)->liveUserIds([$user->id]))->toBe([$user->id]);
});

it('terminates a user whose access and refresh tokens are both expired', function () {
    $user = patient_account();
    native_login($user)->assertOk();
    put_on("user.{$user->id}", $user->id);
    $this->travel(31)->days();

    sweep();

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('terminates a user who fails on two channels once', function () {
    $staff = staff_at();
    put_on("clinic.{$this->locA}", $staff->id);
    put_on("clinic.{$this->locA}.queue", $staff->id);
    ClinicMember::where('user_id', $staff->id)->update(['status' => 'REMOVED', 'removed_at' => now()]);

    $stats = sweep();

    expect($this->pusher->terminated)->toBe([$staff->id])->and($stats['terminated'])->toBe(1);
});

it('is idempotent: a second run right after terminates and records nothing more', function () {
    $staff = staff_at();
    put_on("clinic.{$this->locA}", $staff->id);
    ClinicMember::where('user_id', $staff->id)->update(['status' => 'REMOVED', 'removed_at' => now()]);

    sweep();
    sweep();

    expect($this->pusher->terminated)->toBe([$staff->id])
        ->and(DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_REVOKED')->count())->toBe(1);
});

it('closes a stranger found on a personal channel and keeps the owner', function () {
    $owner = swept(patient_account());
    $stranger = swept(patient_account());
    $this->pusher->channels["presence-user.{$owner->id}"] = 2;
    $this->pusher->members["presence-user.{$owner->id}"] = [$owner->id, $stranger->id];

    sweep();

    expect($this->pusher->terminated)->toBe([$stranger->id])
        ->and($this->pusher->getCalls)->toContain("/channels/presence-user.{$owner->id}/users");
});

it('does not ask for the members of a personal channel with one user', function () {
    $owner = swept(patient_account());
    put_on("user.{$owner->id}", $owner->id);

    sweep();

    expect($this->pusher->getCalls)->toBe(['/channels'])->and($this->pusher->terminated)->toBe([]);
});

it('counts an unknown presence channel and terminates nobody for it', function () {
    $this->pusher->channels['presence-billing.1'] = 1;

    $stats = sweep();

    expect($stats['unknown_channels'])->toBe(1)->and($this->pusher->terminated)->toBe([]);
});

/*
 * Fail open on infrastructure errors.
 */

it('terminates nobody and counts the failure when Reverb answers 5xx or times out', function (Throwable $failure) {
    $staff = staff_at();
    put_on("clinic.{$this->locA}", $staff->id);
    ClinicMember::where('user_id', $staff->id)->update(['status' => 'REMOVED', 'removed_at' => now()]); // would be terminated if Reverb answered
    $this->pusher->listFailWith = $failure;

    $stats = sweep();

    expect($stats['failed'])->toBeTrue()->and($this->pusher->terminated)->toBe([])
        ->and(Cache::get('realtime:sweep:failures'))->toBe(1)
        ->and(Cache::get(RealtimeReconciler::HEARTBEAT_KEY))->toBeNull();
})->with([
    '503' => fn () => new ApiErrorException('down', 503),
    '401' => fn () => new ApiErrorException('bad signature', 401),
    'timeout' => fn () => new ConnectException('timed out', new Request('GET', 'http://localhost')),
]);

it('raises the High event on the third consecutive failure, at most once per 15 minutes, and resets on success', function () {
    $this->pusher->listFailWith = new ApiErrorException('down', 500);

    sweep();
    sweep();
    expect(DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_FAILED')->count())->toBe(0);
    sweep();
    sweep();

    $rows = DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_FAILED')->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->severity)->toBe('HIGH')->and($rows[0]->user_id)->toBeNull();

    $this->pusher->listFailWith = null;
    sweep();
    expect(Cache::get('realtime:sweep:failures'))->toBeNull()->and(Cache::get(RealtimeReconciler::HEARTBEAT_KEY))->toBeInt();
});

it('handles an empty channel list without a TypeError and writes the heartbeat', function () {
    $this->pusher->channels = [];

    $stats = sweep();

    expect($stats['channels'])->toBe(0)->and($stats['failed'])->toBeFalse()->and($this->pusher->terminated)->toBe([])
        ->and(Cache::get(RealtimeReconciler::HEARTBEAT_KEY))->toBeInt();
});

it('counts a 404 on the members of a listed channel as a coverage gap, not success', function () {
    $staff = staff_at();
    put_on("clinic.{$this->locA}", $staff->id);
    ClinicMember::where('user_id', $staff->id)->update(['status' => 'REMOVED', 'removed_at' => now()]);
    $this->pusher->memberStatus["presence-clinic.{$this->locA}"] = 404;

    $stats = sweep();

    expect($stats['gaps'])->toBe(1)->and($stats['members'])->toBe(0)->and($this->pusher->terminated)->toBe([]);
});

it('skips a pair whose authorization raises a database error instead of terminating it', function () {
    $staff = staff_at();
    put_on("clinic.{$this->locA}", $staff->id);
    $this->app->bind(ClinicAccess::class, fn () => new class implements ClinicAccess
    {
        public function allows(User $user, string $permission, string $clinicId): bool
        {
            throw new RuntimeException('db down');
        }

        public function allowsAtLocation(User $user, string $permission, string $locationId): bool
        {
            throw new RuntimeException('db down');
        }

        public function allowsClinicWide(User $user, string $permission, string $clinicId): bool
        {
            throw new RuntimeException('db down');
        }

        public function memberAllowsAtLocation(User $user, string $permission, string $locationId): bool
        {
            throw new RuntimeException('db down');
        }

        public function allowedLocationIds(User $user, string $permission, string $clinicId): array
        {
            return [];
        }

        public function memberClinicIds(User $user): array
        {
            return [];
        }
    });

    $stats = app(RealtimeReconciler::class)->run();

    expect($stats['skipped'])->toBe(1)->and($stats['terminated'])->toBe(0)->and($this->pusher->terminated)->toBe([]);
});

it('stops at the time budget and resumes from a rotating offset next run', function () {
    config(['realtime.reconcile.budget_seconds' => 0]); // one channel per run
    $staff = staff_at();
    foreach ([$this->locA, $this->locB] as $loc) {
        put_on("clinic.{$loc}", $staff->id);
    }
    put_on("clinic.{$this->locA}.queue", $staff->id);

    $first = sweep();
    $second = sweep();
    $third = sweep();

    $members = array_values(array_filter($this->pusher->getCalls, fn ($c) => $c !== '/channels'));
    expect($first['partial'])->toBeTrue()->and($first['channels'])->toBe(1)
        ->and($second['channels'])->toBe(1)->and($third['channels'])->toBe(1)
        ->and(array_unique($members))->toHaveCount(3);
});

it('records a failed termination as REALTIME_TERMINATION_FAILED and reports one alertable exception', function () {
    Exceptions::fake();
    $this->pusher->failWith = new RuntimeException('secret-host:8080 refused');
    $user = patient_account(); // no credential: the sweep wants to terminate
    put_on("user.{$user->id}", $user->id);

    sweep();

    $rows = DB::table('security_events')->where('event_type', 'REALTIME_TERMINATION_FAILED')->get();
    expect($rows)->toHaveCount(1)->and(json_encode($rows[0]))->not->toContain('secret-host');
    Exceptions::assertReported(RealtimeTerminationFailedException::class);
    Exceptions::assertReported(fn (RealtimeTerminationFailedException $e) => ! str_contains($e->getMessage(), $user->id));
});

it('records the failure signal through RealtimeSessions::recordFailure for every caller', function () {
    Exceptions::fake();

    RealtimeSessions::recordFailure(patient_account()->id, 'logout', new RuntimeException('x'));

    Exceptions::assertReportedCount(1);
});

it('is scheduled every minute without overlapping, on one server, in the background', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'realtime:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(5)
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue();

    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('realtime:reconcile');
});

it('runs from the artisan command and does nothing on a driver without connections', function () {
    $this->pusher->channels = [];
    $this->artisan('realtime:reconcile')->assertSuccessful();

    config(['broadcasting.default' => 'null']);
    app(BroadcastManager::class)->purge('null');
    expect(app(RealtimeReconciler::class)->run()['channels'])->toBe(0);
});

/*
 * Review fixes (5.6-0 review round): credential liveness, direct close, degraded state, anomalies.
 */

function web_login(User $user): void
{
    test()->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => TEST_PASSWORD])->assertOk();
    app('auth')->forgetGuards();
}

it('refuses a token sign-in that names no platform, and still records WEB for the cookie path', function () {
    $user = patient_account();

    $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => TEST_PASSWORD])->assertStatus(422);
    $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => TEST_PASSWORD, 'device' => ['name' => 'x']])->assertStatus(422);
    web_login($user);

    expect(DB::table('user_devices')->where('user_id', $user->id)->pluck('platform')->all())->toBe(['WEB']);
});

it('keeps a fresh web session and terminates one idle past the session lifetime plus margin', function () {
    $user = patient_account();
    web_login($user);
    put_on("user.{$user->id}", $user->id);

    expect(sweep()['terminated'])->toBe(0);

    DB::table('user_devices')->where('user_id', $user->id)->update(['last_seen_at' => now()->subMinutes(120 + 4)]);
    expect(sweep()['terminated'])->toBe(0); // inside the margin
    Cache::flush();

    DB::table('user_devices')->where('user_id', $user->id)->update(['last_seen_at' => now()->subMinutes(120 + 6)]);
    sweep();

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('does not count a revoked web device as live', function () {
    $user = patient_account();
    web_login($user);
    DB::table('user_devices')->where('user_id', $user->id)->update(['revoked_at' => now()]);

    expect(app(LiveSessions::class)->liveUserIds([$user->id]))->toBe([]);
});

it('refreshes a web device heartbeat on authenticated requests, at most every five minutes', function () {
    $user = patient_account();
    web_login($user);
    $stamp = fn () => DB::table('user_devices')->where('user_id', $user->id)->value('last_seen_at');
    $created = $stamp();

    $this->travel(2)->minutes();
    $this->withHeader('Origin', WEB_ORIGIN)->getJson('/api/v1/auth/me')->assertOk();
    expect($stamp())->toBe($created); // throttled

    $this->travel(4)->minutes();
    $this->withHeader('Origin', WEB_ORIGIN)->getJson('/api/v1/auth/me')->assertOk();
    expect($stamp())->not->toBe($created);
});

it('does not count used or revoked refresh tokens, or an enrolment-only access token', function () {
    $user = patient_account();
    $login = native_login($user)->json('data');
    DB::table('auth_refresh_tokens')->where('user_device_id', $login['device_id'])->update(['used_at' => now()]);
    DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();
    expect(app(LiveSessions::class)->liveUserIds([$user->id]))->toBe([]);

    DB::table('auth_refresh_tokens')->where('user_device_id', $login['device_id'])->update(['used_at' => null, 'revoked_at' => now()]);
    expect(app(LiveSessions::class)->liveUserIds([$user->id]))->toBe([]);

    $token = $user->createToken('enrol', ['mfa:enroll'], now()->addMinutes(10));
    $token->accessToken->forceFill(['user_device_id' => $login['device_id']])->save();
    expect(app(LiveSessions::class)->liveUserIds([$user->id]))->toBe([]);
});

it('looks up more users than one chunk without failing', function () {
    $ids = array_map(fn () => (string) Str::uuid(), range(1, 1200));

    expect(app(LiveSessions::class)->liveUserIds($ids))->toBe([]);
});

it('closes the socket itself, in this process, without the queue', function () {
    Queue::fake();
    $user = patient_account();
    put_on("user.{$user->id}", $user->id);

    $stats = sweep();

    expect($this->pusher->terminated)->toBe([$user->id])->and($stats['terminated'])->toBe(1);
    Queue::assertNothingPushed();
});

it('does not count a termination that failed, records it, and retries next run', function () {
    Exceptions::fake();
    $this->pusher->failWith = new RuntimeException('secret-host refused');
    $user = patient_account();
    put_on("user.{$user->id}", $user->id);

    $stats = sweep();

    expect($stats['terminated'])->toBe(0)
        ->and(DB::table('security_events')->where('event_type', 'REALTIME_TERMINATION_FAILED')->count())->toBe(1)
        ->and(DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_REVOKED')->count())->toBe(0);
    Exceptions::assertReported(RealtimeTerminationFailedException::class);
});

it('sends the Sentry report even when the security event cannot be written', function () {
    Exceptions::fake();
    $this->app->bind(RecordsSecurityEvents::class, fn () => new class implements RecordsSecurityEvents
    {
        public function record(SecurityEventType $type, ?string $userId = null, array $metadata = [], ?Severity $severity = null): void
        {
            throw new RuntimeException('database down');
        }
    });

    RealtimeSessions::recordFailure(patient_account()->id, 'logout', new RuntimeException('x'));

    Exceptions::assertReported(RealtimeTerminationFailedException::class);
});

it('writes last_ok only for a clean run; a skipped channel leaves it unset but marks the run', function () {
    $staff = staff_at();
    put_on("clinic.{$this->locA}", $staff->id);
    $this->pusher->memberStatus["presence-clinic.{$this->locA}"] = 500;

    sweep();

    expect(Cache::get(RealtimeReconciler::HEARTBEAT_KEY))->toBeNull()->and(Cache::get(RealtimeReconciler::LAST_RUN_KEY))->toBeInt();

    $this->pusher->memberStatus = [];
    sweep();
    expect(Cache::get(RealtimeReconciler::HEARTBEAT_KEY))->toBeInt();
});

it('still terminates a definite negative on another channel when one members call fails, without touching the API failure counter', function () {
    $good = staff_at();
    $bad = staff_at();
    put_on("clinic.{$this->locA}", $good->id);
    put_on("clinic.{$this->locB}", $bad->id);
    ClinicMember::where('user_id', $bad->id)->update(['status' => 'REMOVED', 'removed_at' => now()]);
    $this->pusher->memberStatus["presence-clinic.{$this->locA}"] = 500;

    $stats = sweep();

    expect($this->pusher->terminated)->toBe([$bad->id])->and($stats['skipped'])->toBe(1)
        ->and(Cache::get('realtime:sweep:failures'))->toBeNull()->and(Cache::get('realtime:sweep:degraded'))->toBe(1);
});

it('raises REALTIME_RECONCILE_FAILED and a Sentry report after three consecutive degraded runs, once per 15 minutes', function () {
    Exceptions::fake();
    $staff = staff_at();
    put_on("clinic.{$this->locA}", $staff->id);
    $this->pusher->memberStatus["presence-clinic.{$this->locA}"] = 500; // a skipped channel every run

    sweep();
    sweep();
    expect(DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_FAILED')->count())->toBe(0);
    sweep();
    sweep();

    expect(DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_FAILED')->count())->toBe(1);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(RealtimeReconcileFailedException::class);
});

it('fails open and counts the run degraded when the credential lookup throws', function () {
    $user = patient_account(); // no credential: would be terminated
    put_on("user.{$user->id}", $user->id);
    $this->app->bind(LiveSessions::class, fn () => new class implements LiveSessions
    {
        public function liveUserIds(array $userIds): array
        {
            throw new RuntimeException('db down');
        }
    });

    $stats = app(RealtimeReconciler::class)->run();

    expect($this->pusher->terminated)->toBe([])->and($stats['skipped'])->toBe(1)->and(Cache::get(RealtimeReconciler::HEARTBEAT_KEY))->toBeNull();
});

it('fails open when the user lookup throws', function () {
    $user = patient_account();
    put_on("user.{$user->id}", $user->id);
    DB::beforeExecuting(function (string $query) {
        if (str_contains($query, 'from "users"')) {
            throw new RuntimeException('db down');
        }
    });

    $stats = sweep();

    expect($this->pusher->terminated)->toBe([])->and($stats['skipped'])->toBe(1);
});

it('terminates a member id with no account, and counts a non-UUID member as an anomaly without terminating it', function () {
    $ghost = (string) Str::uuid();
    $owner = swept(patient_account());
    $this->pusher->channels["presence-clinic.{$this->locA}"] = 2;
    $this->pusher->members["presence-clinic.{$this->locA}"] = [$ghost, 'not-a-uuid; drop table users'];
    put_on("user.{$owner->id}", $owner->id);

    $stats = sweep();

    expect($this->pusher->terminated)->toBe([$ghost])->and($stats['anomalies'])->toBe(1);
    $ghostEvent = DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_REVOKED')->first();
    expect($ghostEvent->user_id)->toBeNull();
});

it('records a High anomaly event for more than one user on a personal channel even when the members call fails', function () {
    $owner = swept(patient_account());
    $this->pusher->channels["presence-user.{$owner->id}"] = 2;
    $this->pusher->memberStatus["presence-user.{$owner->id}"] = 500;

    $stats = sweep();

    $event = DB::table('security_events')->where('event_type', 'REALTIME_CHANNEL_ANOMALY')->first();
    expect($event->severity)->toBe('HIGH')->and($stats['anomalies'])->toBe(1);
});

it('every tenth run also lists the members of personal channels and closes a stranger', function () {
    $owner = swept(patient_account());
    $stranger = swept(patient_account());
    $this->pusher->channels["presence-user.{$owner->id}"] = 1; // Reverb says one user; it is a lie the deep run checks
    $this->pusher->members["presence-user.{$owner->id}"] = [$stranger->id];

    for ($i = 0; $i < 9; $i++) {
        sweep();
    }
    expect($this->pusher->terminated)->toBe([]);
    Cache::forget('realtime-reconcile:terminated:'.hash('sha256', $stranger->id));
    sweep();

    expect($this->pusher->terminated)->toBe([$stranger->id]);
});

it('uses a fresh permission cache even when a role permission was removed by raw SQL', function () {
    $staff = staff_at();
    put_on("clinic.{$this->locA}.queue", $staff->id);
    sweep();
    $role = SpatieRole::findByName(Role::ClinicStaff->value, config('rbac.guard'));
    $permission = Permission::findByName('walkins.view', config('rbac.guard'));
    DB::table('role_has_permissions')->where('role_id', $role->id)->where('permission_id', $permission->id)->delete();

    sweep();

    expect($this->pusher->terminated)->toBe([$staff->id]);
});

it('agrees with the real channel callbacks for every registered pattern, for an allowed and a denied user', function () {
    $staff = staff_at($this->locA);
    $outsider = swept(patient_account());
    [$doctor] = verified_doctor('0123456', 'ACTIVE');
    $doctorUser = User::findOrFail($doctor->user_id);
    $ids = ['userId' => $staff->id, 'doctorProfileId' => $doctor->id, 'locationId' => $this->locA];
    $authorizer = app(ChannelAuthorizer::class);
    $callbacks = Broadcast::driver()->getChannels();

    expect($callbacks->keys()->sort()->values()->all())->toBe(collect(array_keys(Channels::PATTERNS))->sort()->values()->all());

    foreach ($callbacks as $pattern => $callback) {
        preg_match('/\{(\w+)\}/', (string) $pattern, $m);
        $id = $ids[$m[1]];
        $ref = Channels::parse('presence-'.str_replace('{'.$m[1].'}', $id, (string) $pattern));
        expect($ref)->not->toBeNull();
        foreach ([$staff, $doctorUser, $outsider] as $user) {
            $real = $callback($user, $id) !== false;
            expect($authorizer->allows($user, $ref))->toBe($real, "pattern {$pattern}");
        }
    }
});

it('refuses a production boot with Reverb scaling enabled', function () {
    expect(fn () => RealtimeConfigGuard::assertSafe('production', 'reverb', ['app.example.ph'], true))->toThrow(RuntimeException::class, 'REVERB_SCALING_ENABLED');
    RealtimeConfigGuard::assertSafe('production', 'reverb', ['app.example.ph'], false);
    RealtimeConfigGuard::assertSafe('local', 'reverb', ['app.example.ph'], true);
    expect(true)->toBeTrue();
});

it('reports the sweep state in readiness: ok, degraded, stale, unknown (numeric strings included)', function (array $keys, string $expected) {
    foreach ($keys as $key => $age) {
        Cache::put("realtime:sweep:{$key}", (string) (time() - $age)); // Redis hands numbers back as strings
    }

    $this->getJson('/api/v1/ready')->assertJsonPath('data.checks.realtime_sweep', $expected);
})->with([
    'ok' => [['last_ok' => 10, 'last_run' => 10], 'ok'],
    'degraded' => [['last_ok' => 900, 'last_run' => 10], 'degraded'],
    'stale' => [['last_ok' => 900, 'last_run' => 900], 'stale'],
    'unknown' => [[], 'unknown'],
]);

it('closes a user\'s sockets on demand with an audited command', function () {
    $user = patient_account();

    $this->artisan('realtime:terminate', ['user' => $user->id, '--reason' => 'ticket 42', '--operator' => 'oncall'])->assertSuccessful();

    expect($this->pusher->terminated)->toBe([$user->id]);
    $event = DB::table('security_events')->where('event_type', 'REALTIME_MANUAL_TERMINATE')->first();
    expect($event->user_id)->toBe($user->id)->and(json_decode($event->metadata, true))->toMatchArray(['operator' => 'oncall', 'reason' => 'ticket 42']);
});

it('refuses the terminate command for a non-UUID or without a reason, and reports a failed call', function () {
    $this->artisan('realtime:terminate', ['user' => 'nope', '--reason' => 'x'])->assertExitCode(2);
    $this->artisan('realtime:terminate', ['user' => (string) Str::uuid()])->assertExitCode(2);
    expect($this->pusher->terminated)->toBe([]);

    $this->pusher->failWith = new RuntimeException('down');
    $this->artisan('realtime:terminate', ['user' => patient_account()->id, '--reason' => 'x'])->assertExitCode(1);
});

/*
 * Final minors.
 */

it('does not alert for partial runs or a few coverage gaps, but does for gaps above the threshold', function () {
    Exceptions::fake();
    config(['realtime.reconcile.budget_seconds' => 0]); // every run is partial
    $staff = staff_at();
    foreach (range(1, 5) as $_) {
        put_on("clinic.{$this->locA}", $staff->id);
        sweep();
    }
    expect(DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_FAILED')->count())->toBe(0)->and(Cache::get('realtime:sweep:degraded'))->toBeNull();

    config(['realtime.reconcile.budget_seconds' => 40]);
    $this->pusher->memberStatus["presence-clinic.{$this->locA}"] = 404; // 1 gap of 1 channel: under the minimum of 3
    foreach (range(1, 4) as $_) {
        sweep();
    }
    expect(DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_FAILED')->count())->toBe(0);

    config(['realtime.reconcile.gap_min_count' => 1]); // now a gap is significant
    foreach (range(1, 3) as $_) {
        sweep();
    }
    expect(DB::table('security_events')->where('event_type', 'REALTIME_RECONCILE_FAILED')->count())->toBe(1);
});

it('caps terminations per run and finds the rest next run', function () {
    config(['realtime.reconcile.max_terminations_per_run' => 2]);
    $users = array_map(fn () => patient_account(), range(1, 5)); // no credentials: all to be closed
    foreach ($users as $u) {
        put_on("user.{$u->id}", $u->id);
    }

    $first = sweep();
    expect($first['terminated'])->toBe(2)->and($first['partial'])->toBeTrue();
    Cache::forget('realtime:sweep:offset');
    foreach (array_slice($this->pusher->terminated, 0, 2) as $done) {
        unset($this->pusher->channels["presence-user.{$done}"]);
    }
    sweep();
    foreach (array_slice($this->pusher->terminated, 2, 2) as $done) {
        unset($this->pusher->channels["presence-user.{$done}"]);
    }
    sweep();

    expect($this->pusher->terminated)->toHaveCount(5)->and(array_unique($this->pusher->terminated))->toHaveCount(5);
});

it('still terminates when the audit event of realtime:terminate cannot be recorded', function () {
    $this->app->bind(RecordsSecurityEvents::class, fn () => new class implements RecordsSecurityEvents
    {
        public function record(SecurityEventType $type, ?string $userId = null, array $metadata = [], ?Severity $severity = null): void
        {
            throw new RuntimeException('database down');
        }
    });
    $user = patient_account();

    $this->artisan('realtime:terminate', ['user' => $user->id, '--reason' => 'ticket 7'])->assertSuccessful();

    expect($this->pusher->terminated)->toBe([$user->id]);
});

it('finishes an MFA login from the other kind of client without a 500', function () {
    $user = account(Role::Patient);
    [$secret] = enroll_mfa($user);
    app('auth')->forgetGuards();
    $device = ['platform' => 'IOS', 'name' => 'Phone'];

    // Native login, verify from a stateful (web) client: still a token sign-in with the stored device.
    $challenge = $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => TEST_PASSWORD, 'device' => $device])->assertOk()->json('data.mfa_challenge_id');
    $this->travel(31)->seconds();
    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $challenge, 'code' => current_totp($secret)])
        ->assertOk()->assertJsonPath('data.tokens.access_token', fn ($t) => is_string($t));

    // Web login, verify from a non-stateful client: refused with 422, the challenge is not consumed.
    app('auth')->forgetGuards();
    $this->withoutHeader('Origin');
    $challenge = $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => TEST_PASSWORD])->assertOk()->json('data.mfa_challenge_id');
    $this->travel(31)->seconds();
    $this->withoutHeader('Origin')->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $challenge, 'code' => current_totp($secret)])->assertStatus(422);
    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/auth/mfa/verify', ['mfa_challenge_id' => $challenge, 'code' => current_totp($secret)])->assertOk();
});

it('answers 422, not 500, when a sign-in reaches the issuer without a platform', function () {
    $user = patient_account();

    expect(fn () => app(SessionIssuer::class)->signIn(request(), $user, [], true, web: false))
        ->toThrow(ValidationException::class);
});
