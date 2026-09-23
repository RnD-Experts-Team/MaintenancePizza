<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Users are replicated from the auth service, so the id is supplied, not generated.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'image_path' => null,
        ];
    }
}
