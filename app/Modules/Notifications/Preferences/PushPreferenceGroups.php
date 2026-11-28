<?php

namespace App\Modules\Notifications\Preferences;

/**
 * The user-switchable PUSH groups (HAG-38). Each group maps to the notification `type` values it covers.
 * A group toggle writes the same enabled flag to every type in it. In-app notices are never switchable.
 *
 * Only types that really send a push are listed. Walk-in CALLED, recall and doctor-delayed notices are
 * in-app only (push: false), so they have no switch.
 */
final class PushPreferenceGroups
{
    public const GROUPS = [
        'walk_in_doctor_checked_out' => ['walkins.doctor_checked_out'],
        'walk_in_cancelled' => ['walkins.cancelled'],
        'walk_in_transferred' => ['walkins.transferred'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::GROUPS);
    }
}
