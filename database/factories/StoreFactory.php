<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * Stores are replicated from an external service, so the id is supplied, not generated.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'store_number' => $this->faker->unique()->numerify('#####-#####'),
        ];
    }
}
