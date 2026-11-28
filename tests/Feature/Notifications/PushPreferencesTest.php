<?php

use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Notifications\Contracts\PushGateway;
use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Notifications\Jobs\SendPushNotification;
use App\Modules\Notifications\Preferences\PushPreferenceGroups;
use App\Modules\Notifications\Services\PushPreferences;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * HAG-38: per-user PUSH switches for walk-in notice groups. The push job already skips a PUSH row that is off;
 * these tests prove the endpoint writes that row and that the in-app notice is left alone.
 */

beforeEach(function () {
    seed_roles();
    fake_messages();
    $this->patient = patient_account();
    $this->headers = signed_in($this->patient);
});

function push_groups(array $overrides = []): array
{
    return array_merge(['walk_in_doctor_checked_out' => true, 'walk_in_cancelled' => true, 'walk_in_transferred' => true], $overrides);
}

function push_body(array $overrides = []): array
{
    return ['push' => push_groups($overrides)];
}

function give_device_token(User $user, string $token): void
{
    $device = new UserDevice(['platform' => 'ANDROID']);
    $device->user()->associate($user)->save();
    DB::table('device_tokens')->insert(['id' => (string) Str::uuid7(), 'user_device_id' => $device->id, 'provider' => 'FCM', 'token' => $token, 'created_at' => now(), 'updated_at' => now()]);
}

it('reports every PUSH group on when no preference row exists', function () {
    test()->getJson('/api/v1/notifications/preferences', $this->headers)
        ->assertOk()
        ->assertJsonPath('data.push', push_groups())
        ->assertJsonMissing(['in_app']);
});

it('switches one group off and keeps the in-app notice, with the push job skipping that group only', function () {
    test()->putJson('/api/v1/notifications/preferences', push_body(['walk_in_cancelled' => false]), $this->headers)
        ->assertOk()
        ->assertJsonPath('data.push.walk_in_cancelled', false)
        ->assertJsonPath('data.push.walk_in_doctor_checked_out', true);

    give_device_token($this->patient, 'fcm-token-off');
    $gateway = $this->mock(PushGateway::class);
    $gateway->shouldNotReceive('send');

    $id = app(SendsNotifications::class)->notify($this->patient->id, 'walkins.cancelled', 'Your walk-in queue entry was cancelled', 'Open the app for details.');

    (new SendPushNotification($id))->handle(app(PushGateway::class));

    expect(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->value('status'))->toBe('SKIPPED')
        ->and(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'IN_APP')->value('status'))->toBe('SENT')
        ->and(DB::table('notifications')->where('id', $id)->where('notifiable_id', $this->patient->id)->exists())->toBeTrue();
});

it('still pushes a group that stays on', function () {
    test()->putJson('/api/v1/notifications/preferences', push_body(['walk_in_doctor_checked_out' => false]), $this->headers)->assertOk();

    give_device_token($this->patient, 'fcm-token-on');
    $gateway = $this->mock(PushGateway::class);
    $gateway->shouldReceive('send')->once()->andReturn(true);

    $id = app(SendsNotifications::class)->notify($this->patient->id, 'walkins.cancelled', 'Your walk-in queue entry was cancelled', 'Open the app for details.');

    (new SendPushNotification($id))->handle(app(PushGateway::class));

    expect(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->value('status'))->toBe('SENT');
});

it('leaves another user with the defaults', function () {
    test()->putJson('/api/v1/notifications/preferences', push_body(['walk_in_doctor_checked_out' => false, 'walk_in_cancelled' => false]), $this->headers)->assertOk();

    $other = patient_account();
    expect(app(PushPreferences::class)->forUser($other->id))->toBe(push_groups());

    give_device_token($other, 'fcm-token-other');
    $gateway = $this->mock(PushGateway::class);
    $gateway->shouldReceive('send')->once()->andReturn(true);

    $id = app(SendsNotifications::class)->notify($other->id, 'walkins.cancelled', 'Your walk-in queue entry was cancelled', 'Open the app for details.');
    (new SendPushNotification($id))->handle(app(PushGateway::class));

    expect(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->value('status'))->toBe('SENT');
});

it('refuses an unknown group with 422', function () {
    test()->putJson('/api/v1/notifications/preferences', push_body(['walk_in_everything' => false]), $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['push']);
});

it('refuses a non-boolean value and a missing group with 422', function () {
    test()->putJson('/api/v1/notifications/preferences', push_body(['walk_in_cancelled' => 'maybe']), $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['push.walk_in_cancelled']);

    $body = push_body();
    unset($body['push']['walk_in_transferred']);
    test()->putJson('/api/v1/notifications/preferences', $body, $this->headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['push.walk_in_transferred']);
});

it('answers 401 without a signed-in user', function () {
    test()->getJson('/api/v1/notifications/preferences')->assertUnauthorized();
    test()->putJson('/api/v1/notifications/preferences', push_body())->assertUnauthorized();
});

it('is stable when the same PUT is repeated, and audits only the first real change by changed keys', function () {
    test()->putJson('/api/v1/notifications/preferences', push_body(['walk_in_cancelled' => false]), $this->headers)->assertOk();
    test()->putJson('/api/v1/notifications/preferences', push_body(['walk_in_cancelled' => false]), $this->headers)
        ->assertOk()
        ->assertJsonPath('data.push.walk_in_cancelled', false);

    expect(DB::table('notification_preferences')->where('user_id', $this->patient->id)->where('channel', 'PUSH')->where('enabled', false)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'notifications.push_preferences_updated')->where('subject_id', $this->patient->id)->count())->toBe(1);

    $audit = DB::table('audit_logs')->where('action', 'notifications.push_preferences_updated')->first();
    expect(json_decode((string) $audit->changes, true))->toBe(['channel' => 'PUSH', 'changed_keys' => ['walk_in_cancelled']]);
});

it('lists only the walk-in types that really send a push, one per kept group', function () {
    expect(PushPreferenceGroups::keys())->toBe(['walk_in_doctor_checked_out', 'walk_in_cancelled', 'walk_in_transferred'])
        ->and(collect(PushPreferenceGroups::GROUPS)->flatten()->all())->toBe(['walkins.doctor_checked_out', 'walkins.cancelled', 'walkins.transferred']);
});

it('sends a PUSH for every type in every kept group through the default notify path', function () {
    give_device_token($this->patient, 'fcm-token-every-group');
    $gateway = $this->mock(PushGateway::class);
    $gateway->shouldReceive('send')->times(3)->andReturn(true);

    foreach (PushPreferenceGroups::GROUPS as $types) {
        foreach ($types as $type) {
            $id = app(SendsNotifications::class)->notify($this->patient->id, $type, 'Generic title', 'Generic body.');
            (new SendPushNotification($id))->handle(app(PushGateway::class));

            expect(DB::table('notification_deliveries')->where('notification_id', $id)->where('channel', 'PUSH')->value('status'))->toBe('SENT');
        }
    }
});
