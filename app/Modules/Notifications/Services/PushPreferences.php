<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Notifications\Preferences\PushPreferenceGroups;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the user's PUSH switches in notification_preferences (the table the push job already checks).
 * No row means ON. Writes only the groups whose state really changes, so a repeated PUT is a no-op.
 */
final class PushPreferences
{
    public function __construct(private readonly RecordsAudit $audit) {}

    /** @return array<string, bool> group => enabled */
    public function forUser(string $userId): array
    {
        $off = DB::table('notification_preferences')->where('user_id', $userId)->where('channel', 'PUSH')->where('enabled', false)
            ->pluck('event_type')->flip();

        $groups = [];
        foreach (PushPreferenceGroups::GROUPS as $group => $types) {
            $groups[$group] = ! collect($types)->contains(fn (string $type): bool => $off->has($type));
        }

        return $groups;
    }

    /**
     * @param  array<string, bool>  $groups  every known group, validated by the caller
     * @return list<string> the groups that changed
     */
    public function update(string $userId, array $groups): array
    {
        return DB::transaction(function () use ($userId, $groups): array {
            $current = $this->forUser($userId);
            $changed = [];
            $now = now();
            $rows = [];

            foreach ($groups as $group => $enabled) {
                if ($current[$group] === $enabled) {
                    continue;
                }
                $changed[] = $group;
                foreach (PushPreferenceGroups::GROUPS[$group] as $type) {
                    $rows[] = ['user_id' => $userId, 'event_type' => $type, 'channel' => 'PUSH', 'enabled' => $enabled, 'created_at' => $now, 'updated_at' => $now];
                }
            }

            if ($rows === []) {
                return [];
            }

            DB::table('notification_preferences')->upsert($rows, ['user_id', 'event_type', 'channel'], ['enabled', 'updated_at']);
            $this->audit->record('notifications.push_preferences_updated', 'user', $userId, ['channel' => 'PUSH', 'changed_keys' => $changed]);

            return $changed;
        });
    }
}
