<?php

namespace Database\Factories;

use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientProfile>
 */
class PatientProfileFactory extends Factory
{
    protected $model = PatientProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d'),
        ];
    }

    /** A dependent without a login of their own. */
    public function withoutAccount(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }
}
