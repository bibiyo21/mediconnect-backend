<?php

namespace App\Modules\Users\Services;

use App\Modules\Users\Contracts\Accounts;
use App\Modules\Users\Models\User;
use App\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\DB;

final class AccountService implements Accounts
{
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function findByMobile(PhoneNumber $mobile): ?User
    {
        return User::where('mobile_e164', $mobile->e164)->first();
    }

    public function register(?string $email, ?PhoneNumber $mobile, string $password, array $profile): User
    {
        return DB::transaction(function () use ($email, $mobile, $password, $profile) {
            $user = new User(['email' => $email, 'mobile_e164' => $mobile?->e164, 'password' => $password]);
            $user->email_verified_at = $email !== null ? now() : null;
            $user->mobile_verified_at = $mobile !== null ? now() : null;
            $user->save();

            $user->profile()->create([
                'first_name' => $profile['first_name'],
                'middle_name' => $profile['middle_name'] ?? null,
                'last_name' => $profile['last_name'],
                'date_of_birth' => $profile['date_of_birth'] ?? null,
                'sex' => $profile['sex'] ?? null,
            ]);

            return $user;
        });
    }

    public function changePassword(User $user, string $password): void
    {
        $user->forceFill(['password' => $password])->save();
    }

    public function updateProfile(User $user, array $fields): void
    {
        if ($fields !== []) {
            $user->profile()->firstOrFail()->update($fields);
        }
    }

    public function replacePrimaryAddress(User $user, ?array $address): void
    {
        DB::transaction(function () use ($user, $address) {
            $user->addresses()->where('is_primary', true)->delete();

            if ($address !== null) {
                $user->addresses()->create([
                    'line1' => $address['line1'],
                    'line2' => $address['line2'] ?? null,
                    'barangay' => $address['barangay'] ?? null,
                    'city' => $address['city'],
                    'province' => $address['province'] ?? null,
                    'postal_code' => $address['postal_code'] ?? null,
                    'country_code' => 'PH',
                    'is_primary' => true,
                ]);
            }
        });
    }

    public function changeEmail(User $user, string $email): void
    {
        $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
    }

    public function changeMobile(User $user, PhoneNumber $mobile): void
    {
        $user->forceFill(['mobile_e164' => $mobile->e164, 'mobile_verified_at' => now()])->save();
    }

    public function markEmailVerified(User $user): void
    {
        $user->forceFill(['email_verified_at' => now()])->save();
    }

    public function markMobileVerified(User $user): void
    {
        $user->forceFill(['mobile_verified_at' => now()])->save();
    }

    public function recordLogin(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}
