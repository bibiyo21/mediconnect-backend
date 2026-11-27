<?php

namespace App\Modules\Notifications\Realtime;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Support\Str;

/**
 * The only places channel names are spelled (architecture.md §15.2, rbac-permissions.md §8).
 * The authorization for each lives in routes/channels.php.
 *
 * Every access-granting channel is a PRESENCE channel (CLAUDE.md §19 allows private and presence
 * channels; there are no public ones, and the public doctor-availability channel D-31 is not
 * owner-approved). Why presence and not private: Reverb ties a connection to a user id only from a
 * presence channel's `channel_data.user_id`, and `terminate_connections` closes only connections that
 * carry one. A private-channel subscriber has no user id, so it could not be cut when its
 * authorization ends (increment 5.4b, M-3; see RealtimeSessions).
 *
 * What a presence channel reveals to co-subscribers: Reverb sends `member_added`, `member_removed`
 * and the member list as `{user_id, user_info}`. Ours is `{user_id: <uuid>, user_info: {}}`:
 * the opaque user UUID and nothing else (no name, email, role or permission). On `user.*` and
 * `doctor.*` the only member is the owner. On `clinic.*` the members are staff of that same
 * location, who already work together and see each other in the REST API, so UUIDs alone leak
 * nothing new; they carry no authority outside the platform.
 *
 * Even an authorized channel only gets hints: ids, counts and queue numbers. Never a patient name,
 * contact detail, note, diagnosis, prescription, membership number or token (see RealtimeEvent).
 * That is also why a stale subscription in the short window before termination is harmless.
 */
final class Channels
{
    /**
     * Every pattern registered in routes/channels.php (names without the `presence-` prefix) and its
     * kind. The sweep parses live channel names with it; a test fails when this and the routes drift.
     */
    public const PATTERNS = [
        'user.{userId}' => ChannelKind::User,
        'doctor.{doctorProfileId}' => ChannelKind::Doctor,
        'clinic.{locationId}' => ChannelKind::Clinic,
        'clinic.{locationId}.queue' => ChannelKind::ClinicQueue,
    ];

    /** Parses a presence channel name as Reverb reports it (with the `presence-` prefix); null when it is none of ours. */
    public static function parse(string $name): ?ChannelRef
    {
        if (! str_starts_with($name, 'presence-')) {
            return null;
        }
        $bare = substr($name, strlen('presence-'));

        foreach (self::PATTERNS as $pattern => $kind) {
            $regex = '/^'.preg_replace('/\{\w+\}/', '([^.]+)', str_replace('.', '\.', $pattern)).'$/';
            if (preg_match($regex, $bare, $m) === 1 && Str::isUuid($m[1])) {
                return new ChannelRef($kind, $m[1]);
            }
        }

        return null;
    }

    public static function user(string $userId): PresenceChannel
    {
        return new PresenceChannel("user.{$userId}");
    }

    public static function doctor(string $doctorProfileId): PresenceChannel
    {
        return new PresenceChannel("doctor.{$doctorProfileId}");
    }

    /** Presence and operations of one clinic location, for its staff. */
    public static function clinic(string $locationId): PresenceChannel
    {
        return new PresenceChannel("clinic.{$locationId}");
    }

    /** The location's walk-in queue (ids, counts and queue numbers; never names); the channel needs `walkins.view` at the location. */
    public static function clinicQueue(string $locationId): PresenceChannel
    {
        return new PresenceChannel("clinic.{$locationId}.queue");
    }
}
