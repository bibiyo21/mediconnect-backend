<?php

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Contracts\PushGateway;

/** Push switched off (the safe production choice until real FCM and APNs delivery exists): nothing is sent, nothing is logged. */
final class DisabledPushGateway implements PushGateway
{
    public function send(string $notificationId, string $type, string $title, string $body, array $deviceTokens, string $collapseKey): bool
    {
        return false;
    }
}
