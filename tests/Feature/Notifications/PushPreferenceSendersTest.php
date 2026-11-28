<?php

use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Notifications\Contracts\PushGateway;
use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Notifications\Jobs\SendPushNotification;
use App\Modules\Notifications\Preferences\PushPreferenceGroups;
use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\Users\Models\User;
use App\Shared\Contracts\Clock;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * HAG-38: each kept PUSH group driven through its real sender with the switch off (no PUSH sent, the IN_APP notice still written),
 * and off-then-on restores the PUSH. The senders are the doctor check-out HOLD, the check-out NOTIFY_AND_CANCEL and the queue transfer.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    consultation_clock($this->clock);
});

/** The walk-in world with one patient who has arrived (WAITING) and a device token. Returns the ids and headers the tests need. */
function pps_arrived_world(object $test, string $token): array
{
    $w = walkin_world(['remote' => true]);
    $staff = staff_of($w);
    doctor_in($w);
    [$user, $profile, $headers] = remote_patient();
    pps_device_token($user, $token);
    $entry = register_walkin($headers, patient_body($w['service'], $profile))->assertCreated()->json('data.id');
    fresh_session();
    wi_post("{$entry}/arrive", $staff)->assertOk();
    fresh_session();

    return compact('w', 'staff', 'user', 'profile', 'headers', 'entry');
}

/** A device with an FCM token for the user, so the push job has somewhere to send. */
function pps_device_token(User $user, string $token): void
{
    $device = new UserDevice(['platform' => 'ANDROID']);
    $device->user()->associate($user)->save();
    DB::table('device_tokens')->insert(['id' => (string) Str::uuid7(), 'user_device_id' => $device->id, 'provider' => 'FCM', 'token' => $token, 'created_at' => now(), 'updated_at' => now()]);
}

/** Sends the queued PUSH job of every notice of this type for this user (the job runs after the sender in production). */
function pps_run_push(string $type, string $userId): void
{
    foreach (DB::table('notifications')->where('type', $type)->where('notifiable_id', $userId)->pluck('id') as $id) {
        (new SendPushNotification((string) $id))->handle(app(PushGateway::class));
    }
}

/** The delivery statuses of one channel for the notices of this type and user. */
function pps_statuses(string $type, string $userId, string $channel): array
{
    return DB::table('notification_deliveries')
        ->join('notifications', 'notifications.id', '=', 'notification_deliveries.notification_id')
        ->where('notifications.type', $type)->where('notifications.notifiable_id', $userId)->where('notification_deliveries.channel', $channel)
        ->pluck('notification_deliveries.status')->all();
}

/** Every group off except the one named; the switch states are the only input the sender reads. */
function pps_only_off(string $group): array
{
    return array_merge(array_fill_keys(PushPreferenceGroups::keys(), true), [$group => false]);
}

it('a check-out HOLD with the doctor-checked-out group off sends no PUSH and still writes the IN_APP notice', function () {
    $t = pps_arrived_world($this, 'tok-hold-off');
    test()->putJson('/api/v1/notifications/preferences', ['push' => pps_only_off('walk_in_doctor_checked_out')], signed_in($t['user']))->assertOk();

    $gateway = $this->mock(PushGateway::class);
    $gateway->shouldNotReceive('send');

    fresh_session();
    test()->postJson('/api/v1/presence/check-out', ['reason' => 'END_OF_SHIFT', 'walkin_disposition' => 'HOLD_FOR_REASSIGNMENT'], $t['w']['doctorHeaders'])
        ->assertOk()->assertJsonPath('data.result.entries_held', 1);

    pps_run_push('walkins.doctor_checked_out', $t['user']->id);

    expect(pps_statuses('walkins.doctor_checked_out', $t['user']->id, 'PUSH'))->toBe(['SKIPPED'])
        ->and(pps_statuses('walkins.doctor_checked_out', $t['user']->id, 'IN_APP'))->toBe(['SENT']);
});

it('a check-out NOTIFY_AND_CANCEL with the cancelled group off sends no PUSH and still writes the IN_APP notice', function () {
    $t = pps_arrived_world($this, 'tok-cancel-off');
    test()->putJson('/api/v1/notifications/preferences', ['push' => pps_only_off('walk_in_cancelled')], signed_in($t['user']))->assertOk();

    $gateway = $this->mock(PushGateway::class);
    $gateway->shouldNotReceive('send');

    fresh_session();
    test()->postJson('/api/v1/presence/check-out', ['reason' => 'END_OF_SHIFT', 'walkin_disposition' => 'NOTIFY_AND_CANCEL'], $t['w']['doctorHeaders'])
        ->assertOk()->assertJsonPath('data.result.entries_cancelled', 1);

    pps_run_push('walkins.cancelled', $t['user']->id);

    expect(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $t['entry'])->where('event_type', 'CANCELLED')->count())->toBe(1)
        ->and(pps_statuses('walkins.cancelled', $t['user']->id, 'PUSH'))->toBe(['SKIPPED'])
        ->and(pps_statuses('walkins.cancelled', $t['user']->id, 'IN_APP'))->toBe(['SENT']);
});

it('a queue transfer with the transferred group off sends no PUSH and still writes the IN_APP notice', function () {
    $t = pps_arrived_world($this, 'tok-transfer-off');
    test()->putJson('/api/v1/notifications/preferences', ['push' => pps_only_off('walk_in_transferred')], signed_in($t['user']))->assertOk();

    $second = wi_second_doctor($t['w']);
    register_walkin($t['staff'], guest_body($second['service'], 'Waiting Guest'))->assertCreated();
    $target = (string) DB::table('walkin_queues')->where('doctor_profile_id', $second['doctor']->id)->value('id');

    $gateway = $this->mock(PushGateway::class);
    $gateway->shouldNotReceive('send');

    wi_post("{$t['entry']}/transfer", $t['staff'], ['walkin_queue_id' => $target])->assertOk();

    pps_run_push('walkins.transferred', $t['user']->id);

    expect(pps_statuses('walkins.transferred', $t['user']->id, 'PUSH'))->toBe(['SKIPPED'])
        ->and(pps_statuses('walkins.transferred', $t['user']->id, 'IN_APP'))->toBe(['SENT']);
});

it('switches each group off, and the push for its types is skipped', function (string $group) {
    $user = patient_account();
    $headers = signed_in($user);
    pps_device_token($user, 'tok-group-off-'.$group);
    test()->putJson('/api/v1/notifications/preferences', ['push' => pps_only_off($group)], $headers)->assertOk()
        ->assertJsonPath("data.push.{$group}", false);

    $gateway = $this->mock(PushGateway::class);
    $gateway->shouldNotReceive('send');

    foreach (PushPreferenceGroups::GROUPS[$group] as $type) {
        $id = app(SendsNotifications::class)->notify($user->id, $type, 'Generic title', 'Generic body.');
        (new SendPushNotification($id))->handle(app(PushGateway::class));

        expect(pps_statuses($type, $user->id, 'PUSH'))->toBe(['SKIPPED'])
            ->and(pps_statuses($type, $user->id, 'IN_APP'))->toBe(['SENT']);
    }
})->with(PushPreferenceGroups::keys());

it('switching a group off and then on again restores the PUSH and audits each real change', function (string $group) {
    $user = patient_account();
    $headers = signed_in($user);
    pps_device_token($user, 'tok-off-on-'.$group);
    test()->putJson('/api/v1/notifications/preferences', ['push' => pps_only_off($group)], $headers)->assertOk();

    $off = $this->mock(PushGateway::class);
    $off->shouldNotReceive('send');
    foreach (PushPreferenceGroups::GROUPS[$group] as $type) {
        $id = app(SendsNotifications::class)->notify($user->id, $type, 'Generic title', 'Generic body.');
        (new SendPushNotification($id))->handle(app(PushGateway::class));
    }

    test()->putJson('/api/v1/notifications/preferences', ['push' => array_fill_keys(PushPreferenceGroups::keys(), true)], $headers)
        ->assertOk()->assertJsonPath("data.push.{$group}", true);

    $on = $this->mock(PushGateway::class);
    $on->shouldReceive('send')->times(count(PushPreferenceGroups::GROUPS[$group]))->andReturn(true);
    foreach (PushPreferenceGroups::GROUPS[$group] as $type) {
        $id = app(SendsNotifications::class)->notify($user->id, $type, 'Generic title', 'Generic body.');
        (new SendPushNotification($id))->handle(app(PushGateway::class));

        expect(pps_statuses($type, $user->id, 'PUSH'))->toContain('SENT');
    }

    $audits = DB::table('audit_logs')->where('action', 'notifications.push_preferences_updated')->where('subject_id', $user->id)->orderBy('id')->pluck('changes')
        ->map(fn ($changes) => json_decode((string) $changes, true))->all();
    expect($audits)->toBe([
        ['channel' => 'PUSH', 'changed_keys' => [$group]],
        ['channel' => 'PUSH', 'changed_keys' => [$group]],
    ]);
})->with(PushPreferenceGroups::keys());

it('answers 429 on the 31st preference call in a minute', function () {
    $headers = signed_in(patient_account());

    for ($i = 0; $i < 30; $i++) {
        test()->getJson('/api/v1/notifications/preferences', $headers)->assertOk();
    }

    test()->getJson('/api/v1/notifications/preferences', $headers)->assertStatus(429);
});
