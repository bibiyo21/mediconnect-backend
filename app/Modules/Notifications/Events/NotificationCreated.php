<?php

namespace App\Modules\Notifications\Events;

use App\Modules\Notifications\Realtime\Channels;
use App\Modules\Notifications\Realtime\RealtimeEvent;

/**
 * A new in-app notification exists (§19 NotificationCreated), on `presence-user.{id}` only.
 * The client refetches it over REST; the hint carries no title or text.
 */
final class NotificationCreated extends RealtimeEvent
{
    public const ALLOWED_KEYS = ['notification_id', 'type'];

    public function __construct(
        public readonly string $userId,
        public readonly string $notificationId,
        public readonly string $type,
    ) {}

    protected function channels(): array
    {
        return [Channels::user($this->userId)];
    }

    protected function payload(): array
    {
        return ['notification_id' => $this->notificationId, 'type' => $this->type];
    }
}
