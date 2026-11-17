<?php

use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Users\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('keeps push tokens unique and removes them with their device', function () {
    $user = User::factory()->create();
    $device = new UserDevice(['platform' => 'ANDROID']);
    $device->user()->associate($user)->save();

    $row = fn () => ['id' => (string) Str::uuid7(), 'user_device_id' => $device->id, 'provider' => 'FCM', 'token' => 'fcm-token-1', 'created_at' => now(), 'updated_at' => now()];
    DB::table('device_tokens')->insert($row());

    expect(fn () => DB::transaction(fn () => DB::table('device_tokens')->insert($row())))->toThrow(QueryException::class, 'device_tokens_token_unique');

    DB::table('user_devices')->where('id', $device->id)->delete();
    expect(DB::table('device_tokens')->count())->toBe(0);
});

it('accepts only known notification channels', function () {
    $user = User::factory()->create();

    expect(fn () => DB::table('notification_preferences')->insert(['user_id' => $user->id, 'event_type' => 'appointment.reminder', 'channel' => 'FAX']))
        ->toThrow(QueryException::class, 'notification_preferences_channel_check');
});
