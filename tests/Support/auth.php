<?php

use App\Modules\Notifications\Contracts\SendsOneTimeMessages;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Rbac\Services\RoleAssignment;
use App\Modules\Users\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakeOneTimeMessages;

const TEST_PASSWORD = 'Correct-horse-42';
const WEB_ORIGIN = 'http://localhost:3000';

function fake_messages(): FakeOneTimeMessages
{
    $fake = new FakeOneTimeMessages;
    app()->instance(SendsOneTimeMessages::class, $fake);

    return $fake;
}

function seed_roles(): void
{
    test()->seed(RbacSeeder::class);
}

function account(Role ...$roles): User
{
    $user = User::factory()->create(['password' => TEST_PASSWORD]);
    foreach ($roles as $role) {
        app(RoleAssignment::class)->assign($user, $role);
    }

    return $user->refresh();
}

/** Native (token) login. */
function native_login(User $user, string $platform = 'IOS'): TestResponse
{
    return test()->postJson('/api/v1/auth/login', [
        'identifier' => $user->email,
        'password' => TEST_PASSWORD,
        'device' => ['platform' => $platform, 'name' => 'Test phone'],
    ]);
}

function bearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

function current_totp(string $secret, int $offsetSteps = 0): string
{
    $g = new Google2FA;

    // Same clock as the server (Carbon, so Laravel's travel() applies).
    return $g->oathTotp($secret, intdiv(now()->getTimestamp(), $g->getKeyRegeneration()) + $offsetSteps);
}

/** Enrolls MFA for a privileged user; returns [secret, recovery codes, full access token]. */
function enroll_mfa(User $user): array
{
    $limited = native_login($user)->json('data.tokens.access_token');
    app('auth')->forgetGuards(); // the login request can leave an earlier test user cached on the guard
    $secret = test()->postJson('/api/v1/auth/mfa/totp/setup', [], bearer($limited))->assertOk()->json('data.secret');
    $confirm = test()->postJson('/api/v1/auth/mfa/totp/confirm', ['code' => current_totp($secret)], bearer($limited))->assertOk()->json('data');
    app('auth')->forgetGuards();

    return [$secret, $confirm['recovery_codes'], $confirm['tokens']['access_token']];
}

/** A patient account as registration creates it: user, user profile and patient profile. */
function patient_account(): User
{
    $user = account(Role::Patient);
    $user->profile()->create(['first_name' => 'Ana', 'last_name' => 'Santos', 'date_of_birth' => '1990-05-01']);
    PatientProfile::factory()->create([
        'user_id' => $user->id, 'first_name' => 'Ana', 'last_name' => 'Santos', 'date_of_birth' => '1990-05-01',
    ]);

    return $user->refresh();
}

/** Signs a user in natively and returns bearer headers. */
function signed_in(User $user): array
{
    return bearer(native_login($user)->assertOk()->json('data.tokens.access_token'));
}
