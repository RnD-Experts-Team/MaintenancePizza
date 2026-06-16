<?php

namespace Database\Factories;

use App\Models\Warranty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warranty>
 */
class WarrantyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'body' => fake()->sentence(),
            'expiry_date' => fake()->dateTimeBetween('now', '+2 years')->format('Y-m-d'),
        ];
    }
}
