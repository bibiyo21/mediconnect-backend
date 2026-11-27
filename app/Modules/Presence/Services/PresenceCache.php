<?php

namespace App\Modules\Presence\Services;

use App\Modules\Presence\Contracts\PresenceVisibility;
use App\Modules\Presence\Enums\PresenceStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Redis `presence:{doctor}:{location}` (architecture.md §15.5; a location key instead of the
 * §25 clinic key, a documented deviation): a rewritten hint of the stored status, never read as
 * the truth (24-hour TTL, version-compared on write). PostgreSQL decides; a failing cache must not fail a committed change.
 */
final class PresenceCache implements PresenceVisibility
{
    public static function key(string $doctorId, string $locationId): string
    {
        return "presence:{$doctorId}:{$locationId}";
    }

    public static function publicKey(string $doctorId): string
    {
        return "presence:public:{$doctorId}";
    }

    /**
     * Rewrites the hint (24 hours, so a forgotten key cannot outlive a forgotten session). A write from
     * the same session with an older version is ignored, so two changes racing to Redis after commit
     * cannot leave the earlier one on top.
     */
    public function put(string $doctorId, string $locationId, string $sessionId, PresenceStatus $status, int $version): void
    {
        try {
            $key = self::key($doctorId, $locationId);
            $current = Cache::get($key);
            if (is_array($current) && ($current['session_id'] ?? null) === $sessionId && (int) ($current['version'] ?? 0) >= $version) {
                return;
            }
            Cache::put($key, ['status' => $status->value, 'version' => $version, 'session_id' => $sessionId], now()->addDay());
        } catch (Throwable $e) {
            Log::warning('Presence cache write failed', ['exception' => $e::class]);
        }
    }

    /** The 10-second public status (see PresenceReader) is dropped as soon as the stored status changes. */
    public function forgetPublic(string $doctorId): void
    {
        try {
            Cache::forget(self::publicKey($doctorId));
        } catch (Throwable $e) {
            Log::warning('Presence cache delete failed', ['exception' => $e::class]);
        }
    }

    public function forget(string $doctorId, string $locationId): void
    {
        try {
            Cache::forget(self::key($doctorId, $locationId));
        } catch (Throwable $e) {
            Log::warning('Presence cache delete failed', ['exception' => $e::class]);
        }
    }
}
