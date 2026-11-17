<?php

namespace App\Modules\Authentication\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The first factor passed; the second factor is still owed. Held in Redis for
 * a few minutes with an attempt counter. Nothing secret is stored here.
 */
final class PendingMfaLogins
{
    /**
     * @param  array<string, mixed>  $deviceInfo
     * @return array{id: string, expires_at: string}
     */
    public function start(string $userId, array $deviceInfo): array
    {
        $id = (string) Str::uuid7();
        $expiresAt = now()->addMinutes((int) config('authentication.mfa.pending_login_minutes'));

        Cache::put($this->key($id), ['user_id' => $userId, 'device' => $deviceInfo, 'attempts' => 0], $expiresAt);

        return ['id' => $id, 'expires_at' => $expiresAt->toIso8601ZuluString()];
    }

    /** @return array{user_id: string, device: array<string, mixed>, attempts: int}|null */
    public function find(string $id): ?array
    {
        /** @var array{user_id: string, device: array<string, mixed>, attempts: int}|null $pending */
        $pending = Cache::get($this->key($id));

        return is_array($pending) ? $pending : null;
    }

    /** Counts a failed attempt; drops the pending login once attempts run out. */
    public function fail(string $id): void
    {
        $pending = $this->find($id);

        if ($pending === null) {
            return;
        }

        $pending['attempts']++;

        if ($pending['attempts'] >= (int) config('authentication.mfa.max_attempts')) {
            $this->finish($id);

            return;
        }

        Cache::put($this->key($id), $pending, now()->addMinutes((int) config('authentication.mfa.pending_login_minutes')));
    }

    public function finish(string $id): void
    {
        Cache::forget($this->key($id));
    }

    private function key(string $id): string
    {
        return 'mfa_login:'.$id;
    }
}
