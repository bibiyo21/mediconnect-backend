<?php

namespace App\Modules\Notifications\Realtime;

use Illuminate\Broadcasting\PrivateChannel;

/**
 * The only places channel names are spelled (architecture.md §15.2, rbac-permissions.md §8).
 * Private channels only: the public doctor-availability channel (D-31) is not owner-approved
 * and conflicts with CLAUDE.md §19. The authorization for each lives in routes/channels.php.
 *
 * Even a private channel only gets hints: ids, counts and queue numbers. Never a patient name,
 * contact detail, note, diagnosis, prescription, membership number or token (see RealtimeEvent).
 */
final class Channels
{
    public static function user(string $userId): PrivateChannel
    {
        return new PrivateChannel("user.{$userId}");
    }

    public static function doctor(string $doctorProfileId): PrivateChannel
    {
        return new PrivateChannel("doctor.{$doctorProfileId}");
    }

    /** Presence and operations of one clinic location, for its staff. */
    public static function clinic(string $locationId): PrivateChannel
    {
        return new PrivateChannel("clinic.{$locationId}");
    }

    /** The location's walk-in queue (ids, counts and queue numbers; never names); the channel needs `walkins.view` at the location. */
    public static function clinicQueue(string $locationId): PrivateChannel
    {
        return new PrivateChannel("clinic.{$locationId}.queue");
    }
}
