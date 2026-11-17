<?php

use App\Modules\Authentication\Enums\DevicePlatform;
use App\Modules\Authentication\Models\UserDevice;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\UserAddress;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('gives users time-ordered UUIDv7 keys (D-01)', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    expect($first->id[14])->toBe('7')
        ->and(strcmp($first->id, $second->id))->toBeLessThan(0);
});

it('hashes passwords and never serializes them', function () {
    $user = User::factory()->create(['password' => 'plain-secret-123']);

    expect($user->getAttributes()['password'])->not->toBe('plain-secret-123')
        ->and(Hash::check('plain-secret-123', $user->password))->toBeTrue()
        ->and($user->toArray())->not->toHaveKey('password');
});

it('defaults new users to ACTIVE and English', function () {
    $user = User::factory()->create()->refresh();

    expect($user->status)->toBe(UserStatus::Active)->and($user->locale)->toBe('en');
});

it('treats emails case-insensitively and keeps them unique', function () {
    User::factory()->create(['email' => 'Ana.Santos@example.com']);

    expect(User::where('email', 'ana.santos@EXAMPLE.com')->exists())->toBeTrue()
        ->and(fn () => User::factory()->create(['email' => 'ANA.SANTOS@example.com']))
        ->toThrow(QueryException::class, 'users_email_unique');
});

it('keeps mobile numbers unique', function () {
    User::factory()->create(['mobile_e164' => '+639171234567']);

    expect(fn () => User::factory()->create(['mobile_e164' => '+639171234567']))
        ->toThrow(QueryException::class, 'users_mobile_e164_unique');
});

it('enforces user constraints in the database', function (array $attributes, string $constraint) {
    expect(fn () => DB::table('users')->insert(array_merge([
        'id' => (string) Str::uuid7(),
        'password' => 'x',
        'email' => 'c'.Str::random(8).'@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes)))->toThrow(QueryException::class, $constraint);
})->with([
    'needs email or mobile' => [['email' => null, 'mobile_e164' => null], 'users_contact_check'],
    'mobile must be E.164' => [['mobile_e164' => '09171234567'], 'users_mobile_format_check'],
    'known statuses only' => [['status' => 'BANNED'], 'users_status_check'],
    'supported locales only' => [['locale' => 'es'], 'users_locale_check'],
]);

it('allows one primary address per user', function () {
    $user = User::factory()->create();
    $address = ['line1' => '1 Ayala Ave', 'city' => 'Makati', 'is_primary' => true];

    $user->addresses()->create($address);
    $user->addresses()->create([...$address, 'is_primary' => false]);

    // The failing insert runs in a savepoint so the test transaction stays usable.
    expect(fn () => DB::transaction(fn () => $user->addresses()->create($address)))->toThrow(QueryException::class, 'user_addresses_one_primary')
        ->and(UserAddress::count())->toBe(2);
});

it('allows a patient profile without an account, for dependents (C-6)', function () {
    $dependent = PatientProfile::factory()->withoutAccount()->create(['first_name' => 'Lia']);

    expect($dependent->user_id)->toBeNull()
        ->and($dependent->refresh()->first_name)->toBe('Lia');
});

it('allows at most one patient profile per account', function () {
    $patient = PatientProfile::factory()->create();

    expect(fn () => PatientProfile::factory()->create(['user_id' => $patient->user_id]))
        ->toThrow(QueryException::class, 'patient_profiles_user_id_unique');
});

it('refuses to hard-delete a user who still has dependent rows', function () {
    $patient = PatientProfile::factory()->create();

    expect(fn () => DB::table('users')->where('id', $patient->user_id)->delete())
        ->toThrow(QueryException::class, 'foreign key');
});

it('soft-deletes users instead of removing them', function () {
    $user = User::factory()->create();
    $user->delete();

    expect(User::find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull();
});

it('records devices with a known platform', function () {
    $user = User::factory()->create();

    $device = new UserDevice(['platform' => DevicePlatform::Ios, 'device_name' => 'iPhone']);
    $device->user()->associate($user)->save();

    expect($device->refresh()->platform)->toBe(DevicePlatform::Ios)
        ->and($device->isRevoked())->toBeFalse()
        ->and(fn () => DB::table('user_devices')->where('id', $device->id)->update(['platform' => 'BLACKBERRY']))
        ->toThrow(QueryException::class, 'user_devices_platform_check');
});
