<?php

namespace Database\Factories;

use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Test data only. Mobile numbers use the 0917 000 xxxx range; never seed real people.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'mobile_e164' => '+639170'.fake()->unique()->numerify('######'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'locale' => 'en',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null, 'mobile_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }
}
