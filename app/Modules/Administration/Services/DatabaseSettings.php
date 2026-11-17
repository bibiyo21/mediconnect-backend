<?php

namespace App\Modules\Administration\Services;

use App\Modules\Administration\Contracts\Settings;
use App\Modules\Audit\Contracts\RecordsAudit;
use App\Shared\Exceptions\InvalidValue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Settings with a short cache. Every change is audited with its old and new
 * value, in the same transaction. Keys that look like secrets are refused.
 */
final class DatabaseSettings implements Settings
{
    private const CACHE_SECONDS = 300;

    /** A dot-separated segment equal to, or ending in, one of these words names a secret. */
    private const SECRET_WORDS = ['password', 'secret', 'token', 'api_key', 'apikey', 'private_key', 'credentials'];

    public function __construct(private readonly RecordsAudit $audit) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $stored = Cache::remember($this->cacheKey($key), self::CACHE_SECONDS, function () use ($key) {
            $value = DB::table('system_settings')->where('key', $key)->value('value');

            // Wrapped so a missing setting is cached too (as "absent"), not looked up every time.
            return ['exists' => $value !== null, 'value' => $value === null ? null : json_decode((string) $value, true)];
        });

        return $stored['exists'] ? $stored['value'] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (preg_match('/^[a-z][a-z0-9_.]{2,99}$/', $key) !== 1) {
            throw new InvalidValue('Setting keys use lowercase letters, digits, dots and underscores.');
        }

        if ($this->namesASecret($key)) {
            throw new InvalidValue('Secrets must not be stored as system settings.');
        }

        DB::transaction(function () use ($key, $value) {
            $old = DB::table('system_settings')->where('key', $key)->lockForUpdate()->value('value');

            DB::table('system_settings')->upsert(
                [['key' => $key, 'value' => json_encode($value), 'updated_by' => Auth::id(), 'updated_at' => now()]],
                ['key'],
                ['value', 'updated_by', 'updated_at'],
            );

            $this->audit->record('settings.changed', 'system_setting', $key, [
                'old' => $old === null ? null : json_decode((string) $old, true),
                'new' => $value,
            ]);
        });

        DB::afterCommit(fn () => Cache::forget($this->cacheKey($key)));
    }

    /** "sms.api_key" and "auth.refresh_token" name secrets; "auth.access_token_minutes" does not. */
    private function namesASecret(string $key): bool
    {
        foreach (explode('.', $key) as $segment) {
            foreach (self::SECRET_WORDS as $word) {
                if ($segment === $word || str_ends_with($segment, '_'.$word)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function cacheKey(string $key): string
    {
        return 'settings:'.$key;
    }
}
