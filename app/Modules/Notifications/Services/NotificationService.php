<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\SendsNotifications;
use App\Modules\Notifications\Events\NotificationCreated;
use App\Modules\Notifications\Jobs\SendPushNotification;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationService implements SendsNotifications
{
    public function notify(string $userId, string $type, string $title, string $body, array $data = []): string
    {
        $id = (string) Str::uuid7();
        $now = now();

        DB::table('notifications')->insert([
            'id' => $id, 'type' => $type, 'notifiable_type' => (new User)->getMorphClass(), 'notifiable_id' => $userId,
            'data' => json_encode([...$data, 'title' => $title, 'body' => $body]), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('notification_deliveries')->insert(['notification_id' => $id, 'channel' => 'IN_APP', 'provider' => 'database', 'status' => 'SENT', 'attempts' => 1, 'sent_at' => $now]);
        DB::table('notification_deliveries')->insert(['notification_id' => $id, 'channel' => 'PUSH', 'status' => 'QUEUED']);

        // Hints and push go out only if the surrounding transaction commits (RealtimeEvent and the job both wait for it).
        NotificationCreated::dispatch($userId, $id, $type);
        SendPushNotification::dispatch($id)->afterCommit();

        return $id;
    }
}
