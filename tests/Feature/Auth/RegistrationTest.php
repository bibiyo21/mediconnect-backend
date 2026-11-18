<?php

use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
});

function register_payload(array $overrides = []): array
{
    return array_merge([
        'password' => TEST_PASSWORD,
        'password_confirmation' => TEST_PASSWORD,
        'first_name' => 'Ana',
        'last_name' => 'Santos',
        'date_of_birth' => '1990-05-01',
        'accept_privacy_notice_version' => config('authentication.legal.privacy_notice_version'),
        'accept_terms_version' => config('authentication.legal.terms_version'),
        'device' => ['platform' => 'ANDROID', 'name' => 'Pixel'],
    ], $overrides);
}

/** Runs step 1 and the code check; returns the verification token. */
function verified_registration(array $contact): string
{
    $challenge = test()->postJson('/api/v1/auth/otp', ['purpose' => 'REGISTER', ...$contact])->assertStatus(202)->json('data.challenge_id');
    $destination = isset($contact['email']) ? strtolower($contact['email']) : '+639171234567';
    $code = test()->messages->lastCodeFor($destination);

    return test()->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $code])->assertOk()->json('data.verification_token');
}

it('registers with a verified email and signs the new patient in', function () {
    $token = verified_registration(['email' => 'Ana@Example.com']);

    $response = $this->postJson('/api/v1/auth/register', register_payload(['email' => 'Ana@Example.com', 'verification_token' => $token]));

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'ana@example.com')
        ->assertJsonPath('data.user.email_verified', true)
        ->assertJsonPath('data.user.roles', ['PATIENT'])
        ->assertJsonPath('data.mfa.setup_required', false)
        ->assertJsonStructure(['data' => ['tokens' => ['access_token', 'refresh_token', 'access_token_expires_at']]]);

    $user = User::where('email', 'ana@example.com')->firstOrFail();

    expect(PatientProfile::where('user_id', $user->id)->value('first_name'))->toBe('Ana')
        ->and(DB::table('consent_records')->where('user_id', $user->id)->pluck('consent_type')->sort()->values()->all())
        ->toBe(['PRIVACY_NOTICE', 'TERMS']);

    $this->getJson('/api/v1/auth/me', bearer($response->json('data.tokens.access_token')))->assertOk();
});

it('registers with a verified mobile number in any local spelling', function () {
    $token = verified_registration(['mobile' => '0917 123 4567']);

    $this->postJson('/api/v1/auth/register', register_payload(['mobile' => '+63 917-123-4567', 'verification_token' => $token]))
        ->assertCreated()
        ->assertJsonPath('data.user.mobile_masked', '+63 9•• ••• 4567')
        ->assertJsonPath('data.user.mobile_verified', true);
});

it('answers a registration code request identically whether or not the account exists', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $existing = $this->postJson('/api/v1/auth/otp', ['purpose' => 'REGISTER', 'email' => 'taken@example.com']);
    $fresh = $this->postJson('/api/v1/auth/otp', ['purpose' => 'REGISTER', 'email' => 'new@example.com']);

    $shape = fn ($r) => [$r->status(), array_keys($r->json('data')), $r->json('message'), $r->json('data.channel')];

    expect($shape($existing))->toBe($shape($fresh))
        ->and($this->messages->lastCodeFor('taken@example.com'))->toBeNull()
        ->and($this->messages->notices)->toHaveCount(1)
        ->and($this->messages->lastCodeFor('new@example.com'))->not->toBeNull();
});

it('cannot register an address that already has an account, even with a guessed challenge', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $challenge = $this->postJson('/api/v1/auth/otp', ['purpose' => 'REGISTER', 'email' => 'taken@example.com'])->json('data.challenge_id');

    // No code was ever created for this challenge, so every guess fails.
    $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => '000000'])
        ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('uses a verification token once, for its own address only', function () {
    $token = verified_registration(['email' => 'ana@example.com']);

    $this->postJson('/api/v1/auth/register', register_payload(['email' => 'someone-else@example.com', 'verification_token' => $token]))
        ->assertStatus(422)->assertJsonValidationErrors('verification_token', 'errors');

    $this->postJson('/api/v1/auth/register', register_payload(['email' => 'ana@example.com', 'verification_token' => $token]))->assertCreated();

    $this->postJson('/api/v1/auth/register', register_payload(['email' => 'ana@example.com', 'verification_token' => $token]))
        ->assertStatus(422);
});

it('refuses weak passwords, stale legal versions and future birth dates', function (array $override, string $field) {
    $token = verified_registration(['email' => 'ana@example.com']);

    $this->postJson('/api/v1/auth/register', register_payload(['email' => 'ana@example.com', 'verification_token' => $token, ...$override]))
        ->assertStatus(422)->assertJsonValidationErrors($field, 'errors');

    expect(User::count())->toBe(0);
})->with([
    'short password' => [['password' => 'abc123', 'password_confirmation' => 'abc123'], 'password'],
    'no digits' => [['password' => 'onlyletters-here', 'password_confirmation' => 'onlyletters-here'], 'password'],
    'old privacy notice' => [['accept_privacy_notice_version' => 'v0'], 'accept_privacy_notice_version'],
    'birth date in the future' => [['date_of_birth' => now()->addDay()->toDateString()], 'date_of_birth'],
    'no birth date (D-37)' => [['date_of_birth' => null], 'date_of_birth'],
    'under 18 (D-37)' => [['date_of_birth' => now('Asia/Manila')->subYears(18)->addDay()->toDateString()], 'date_of_birth'],
]);

it('accepts someone who turns 18 today, judged on the Manila calendar (D-37)', function () {
    // 2026-11-18 01:00 in Manila is still 2026-11-17 in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 01:00', 'Asia/Manila'));
    $token = verified_registration(['email' => 'ana@example.com']);

    $this->postJson('/api/v1/auth/register', register_payload(['email' => 'ana@example.com', 'verification_token' => $token, 'date_of_birth' => '2008-11-18']))
        ->assertCreated();
});

it('requires exactly one of email or mobile', function () {
    $this->postJson('/api/v1/auth/otp', ['purpose' => 'REGISTER'])->assertStatus(422);
    $this->postJson('/api/v1/auth/otp', ['purpose' => 'REGISTER', 'email' => 'a@example.com', 'mobile' => '09171234567'])->assertStatus(422);
    $this->postJson('/api/v1/auth/otp', ['purpose' => 'REGISTER', 'mobile' => '0281234567'])
        ->assertStatus(422)->assertJsonValidationErrors('mobile', 'errors');
});
