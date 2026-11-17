<?php

use App\Modules\Authentication\Exceptions\AuthFailure;
use App\Modules\Authentication\Models\RefreshToken;
use App\Modules\Authentication\Services\RefreshTokenService;
use App\Modules\Rbac\Enums\Role;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Two clients present the same refresh token at the same moment. Rotation
 * locks the token row, so one waits; when it proceeds it sees the token was
 * used and treats it as reuse. Both can never get a new pair.
 */

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toEndWith('_test');
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
    DB::purge('pgsql_second');
    $this->seed(RbacSeeder::class);
    fake_messages();
});

afterEach(function () {
    DB::statement('RESET lock_timeout');
    Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
});

it('lets only one of two simultaneous refreshes with the same token succeed', function () {
    $tokens = native_login(account(Role::Patient))->json('data.tokens');
    $hash = hash('sha256', $tokens['refresh_token']);

    // Client B is mid-rotation: it holds the row lock and has marked the token used.
    $other = DB::connection('pgsql_second');
    $other->beginTransaction();
    $other->table('auth_refresh_tokens')->where('token_hash', $hash)->lockForUpdate()->first();
    $other->table('auth_refresh_tokens')->where('token_hash', $hash)->update(['used_at' => now()]);

    // Client A cannot read past the lock while B is in flight.
    DB::statement("SET lock_timeout = '300ms'");
    expect(fn () => app(RefreshTokenService::class)->rotate($tokens['refresh_token']))
        ->toThrow(QueryException::class, 'lock timeout');
    DB::statement('RESET lock_timeout');

    // B commits; A retries and is treated as reuse, which revokes the family.
    $other->commit();
    expect(fn () => app(RefreshTokenService::class)->rotate($tokens['refresh_token']))->toThrow(AuthFailure::class);

    expect(RefreshToken::where('token_hash', $hash)->value('revoked_at'))->not->toBeNull()
        ->and(DB::table('security_events')->where('event_type', 'REFRESH_TOKEN_REUSE')->count())->toBe(1);
});
