<?php

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Contracts\PushGateway;
use Illuminate\Support\Facades\Log;

/** LOCAL and test driver (refused in production): records that a push would be sent, and reports it as NOT delivered. Logs ids and a count, never the text or a token. */
final class LogPushGateway implements PushGateway
{
    public function send(string $notificationId, string $type, string $title, string $body, array $deviceTokens, string $collapseKey): bool
    {
        Log::info('Push notification (log driver)', ['notification_id' => $notificationId, 'type' => $type, 'devices' => count($deviceTokens)]);

        return false; // recorded, not delivered
    }
}
