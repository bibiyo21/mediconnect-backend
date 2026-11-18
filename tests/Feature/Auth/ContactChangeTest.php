<?php

use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
});

/** Requests a code for $contact and verifies it; returns the verification token. */
function change_contact_token(array $headers, array $contact): string
{
    $challenge = test()->postJson('/api/v1/auth/contact/otp', [...$contact, 'current_password' => TEST_PASSWORD], $headers)
        ->assertStatus(202)->json('data.challenge_id');
    $destination = isset($contact['email']) ? mb_strtolower($contact['email']) : '+639181234567';
    $code = test()->messages->lastCodeFor($destination);
    expect($code)->not->toBeNull();

    return test()->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $code])->assertOk()->json('data.verification_token');
}

it('changes the email after the password and a code sent to the new address', function () {
    $user = patient_account();
    $old = $user->email;
    $headers = signed_in($user);

    $token = change_contact_token($headers, ['email' => 'New.Address@Example.com']);
    $this->putJson('/api/v1/auth/contact', ['email' => 'New.Address@Example.com', 'verification_token' => $token], $headers)
        ->assertOk()->assertJsonPath('data.email', 'new.address@example.com')->assertJsonPath('data.email_verified', true);

    expect(collect($this->messages->notices)->firstWhere('destination', $old)['notice'] ?? '')->toContain('was changed');
    expect(DB::table('security_events')->where('event_type', 'CONTACT_CHANGED')->count())->toBe(1);
});

it('adds a mobile number to an email-only account without notifying anyone', function () {
    $user = patient_account();
    $user->forceFill(['mobile_e164' => null, 'mobile_verified_at' => null])->save();
    $headers = signed_in($user);

    $token = change_contact_token($headers, ['mobile' => '0918 123 4567']);
    $this->putJson('/api/v1/auth/contact', ['mobile' => '09181234567', 'verification_token' => $token], $headers)
        ->assertOk()->assertJsonPath('data.mobile_verified', true);

    expect($user->refresh()->mobile_e164)->toBe('+639181234567')->and($this->messages->notices)->toBe([]);
});

it('requires the current password before sending any code', function () {
    $user = patient_account();

    $this->postJson('/api/v1/auth/contact/otp', ['email' => 'x@example.com', 'current_password' => 'wrong-password-1'], signed_in($user))
        ->assertStatus(422)->assertJsonValidationErrors('current_password', 'errors');
    expect($this->messages->lastCodeFor('x@example.com'))->toBeNull();
});

it('refuses the address already on the account', function () {
    $user = patient_account();

    $this->postJson('/api/v1/auth/contact/otp', ['email' => strtoupper($user->email), 'current_password' => TEST_PASSWORD], signed_in($user))
        ->assertStatus(422)->assertJsonValidationErrors('email', 'errors');
});

it('gives the same answer for an address owned by someone else, sends no code, and tells the owner', function () {
    $user = patient_account();
    $owner = patient_account();

    $this->postJson('/api/v1/auth/contact/otp', ['email' => $owner->email, 'current_password' => TEST_PASSWORD], signed_in($user))
        ->assertStatus(202)->assertJsonStructure(['data' => ['challenge_id', 'channel', 'destination_masked', 'expires_at']]);

    expect($this->messages->lastCodeFor(mb_strtolower($owner->email)))->toBeNull()
        ->and(collect($this->messages->notices)->firstWhere('destination', mb_strtolower($owner->email))['notice'] ?? '')->toContain('different MediConnect account');
});

it('will not accept a token for a different address than the one proven', function () {
    $user = patient_account();
    $headers = signed_in($user);
    $token = change_contact_token($headers, ['email' => 'proven@example.com']);

    $this->putJson('/api/v1/auth/contact', ['email' => 'other@example.com', 'verification_token' => $token], $headers)->assertStatus(422);
    expect($user->refresh()->email)->not->toBe('other@example.com');
});

it('will not accept another user\'s token', function () {
    $alice = patient_account();
    $bob = patient_account();
    $token = change_contact_token(signed_in($alice), ['email' => 'shared@example.com']);
    $bobHeaders = signed_in($bob);
    $this->app['auth']->forgetGuards(); // the test client would otherwise keep Alice as the signed-in user

    expect($this->getJson('/api/v1/auth/me', $bobHeaders)->json('data.id'))->toBe($bob->id);
    $this->app['auth']->forgetGuards();
    $this->putJson('/api/v1/auth/contact', ['email' => 'shared@example.com', 'verification_token' => $token], $bobHeaders)->assertStatus(422);
    expect($bob->refresh()->email)->not->toBe('shared@example.com');
});

it('uses each token once', function () {
    $user = patient_account();
    $headers = signed_in($user);
    $token = change_contact_token($headers, ['email' => 'once@example.com']);

    $this->putJson('/api/v1/auth/contact', ['email' => 'once@example.com', 'verification_token' => $token], $headers)->assertOk();
    $this->putJson('/api/v1/auth/contact', ['email' => 'once@example.com', 'verification_token' => $token], $headers)->assertStatus(422);
});

it('reports a clash when the address was taken after the code was sent', function () {
    $user = patient_account();
    $headers = signed_in($user);
    $token = change_contact_token($headers, ['email' => 'race@example.com']);
    User::factory()->create(['email' => 'race@example.com']);

    $this->putJson('/api/v1/auth/contact', ['email' => 'race@example.com', 'verification_token' => $token], $headers)
        ->assertStatus(422)->assertJsonValidationErrors('email', 'errors');
});

it('signs out every other device but keeps the current one', function () {
    $user = patient_account();
    $other = signed_in($user);
    $current = signed_in($user);

    $token = change_contact_token($current, ['email' => 'devices@example.com']);
    $this->putJson('/api/v1/auth/contact', ['email' => 'devices@example.com', 'verification_token' => $token], $current)->assertOk();

    $this->getJson('/api/v1/auth/me', $current)->assertOk();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/auth/me', $other)->assertStatus(401);
    expect(UserDevice::where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(1);
});

it('keeps contact-change codes out of the public code endpoint', function () {
    $this->postJson('/api/v1/auth/otp', ['purpose' => 'CHANGE_EMAIL', 'email' => 'x@example.com'])
        ->assertStatus(422)->assertJsonValidationErrors('purpose', 'errors');
});

it('requires sign-in', function () {
    $this->postJson('/api/v1/auth/contact/otp', ['email' => 'x@example.com', 'current_password' => 'x'])->assertStatus(401);
    $this->putJson('/api/v1/auth/contact', ['email' => 'x@example.com', 'verification_token' => str_repeat('a', 64)])->assertStatus(401);
});
