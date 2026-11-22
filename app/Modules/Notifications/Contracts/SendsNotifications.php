<?php

namespace App\Modules\Notifications\Contracts;

/**
 * An in-app notification for one user (architecture.md §15.2, CLAUDE.md §18.3).
 *
 * The `notifications` row is written in the caller's transaction, so it exists if and only if
 * the change committed. After commit, a `NotificationCreated` hint goes to `presence-user.{id}`
 * and a push is queued. Text must be generic: never a diagnosis, prescription, membership
 * number or patient name, because push text shows on a lock screen.
 */
interface SendsNotifications
{
    /**
     * @param  array<string, scalar|null>  $data  identifiers only (for example a location id), merged into the row's `data`
     * @return string the notification id
     */
    public function notify(string $userId, string $type, string $title, string $body, array $data = []): string;
}
