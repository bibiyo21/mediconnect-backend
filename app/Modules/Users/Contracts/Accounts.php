<?php

namespace App\Modules\Users\Contracts;

use App\Modules\Users\Models\User;
use App\Shared\ValueObjects\PhoneNumber;

/**
 * The Users module's write surface for accounts. Other modules create and
 * change accounts only through this contract.
 */
interface Accounts
{
    public function findByEmail(string $email): ?User;

    public function findByMobile(PhoneNumber $mobile): ?User;

    /**
     * Creates the account and its profile. The contact used to register is
     * already proven by a one-time code, so it is stored as verified.
     *
     * @param  array{first_name: string, last_name: string, middle_name?: string|null, date_of_birth?: string|null, sex?: string|null}  $profile
     */
    public function register(?string $email, ?PhoneNumber $mobile, string $password, array $profile): User;

    public function changePassword(User $user, string $password): void;

    /**
     * Updates the shared personal profile (user_profiles). Only the given keys change.
     *
     * @param  array{first_name?: string, middle_name?: string|null, last_name?: string, date_of_birth?: string, sex?: string|null, preferred_language?: string}  $fields
     */
    public function updateProfile(User $user, array $fields): void;

    /**
     * Replaces the user's primary address, or removes it when null.
     *
     * @param  array{line1: string, line2?: string|null, barangay?: string|null, city: string, province?: string|null, postal_code?: string|null}|null  $address
     */
    public function replacePrimaryAddress(User $user, ?array $address): void;

    public function markEmailVerified(User $user): void;

    public function markMobileVerified(User $user): void;

    public function recordLogin(User $user): void;
}
