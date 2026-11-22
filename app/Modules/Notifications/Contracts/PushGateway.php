<?php

namespace App\Modules\Notifications\Contracts;

/**
 * Mobile push delivery (FCM and APNs). Phase 5 ships only the log and disabled drivers (plan Q11): real
 * delivery waits for a Firebase project and credentials from the owner. Title and body are
 * generic by contract; they may appear on a lock screen.
 */
interface PushGateway
{
    /**
     * @param  list<string>  $deviceTokens  the user's active tokens
     * @param  string  $collapseKey  the notification id, as the provider's dedupe key (FCM collapse_key, APNs apns-collapse-id): a rare re-send of the same notification replaces the first instead of showing twice. The log and disabled drivers ignore it.
     * @return bool true only when the push was actually handed to a provider; the log and disabled drivers return false
     */
    public function send(string $notificationId, string $type, string $title, string $body, array $deviceTokens, string $collapseKey): bool;
}
