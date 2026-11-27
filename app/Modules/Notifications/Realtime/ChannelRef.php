<?php

namespace App\Modules\Notifications\Realtime;

/** A parsed channel name: its kind and the one id it carries (user, doctor profile or location). */
final readonly class ChannelRef
{
    public function __construct(public ChannelKind $kind, public string $id) {}
}
