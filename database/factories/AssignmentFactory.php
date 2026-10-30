<?php

namespace Database\Factories;

use App\Models\Assignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 */
class AssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'work',
            'assigned_date' => fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d'),
            'assigned_hour' => null,
        ];
    }
}
