<?php

namespace Database\Factories;

use App\Enums\PartUsagePayer;
use App\Enums\PartUsageSource;
use App\Models\Part;
use App\Models\PartUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartUsage>
 */
class PartUsageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(1, 5);
        $unitCost = $this->faker->randomFloat(2, 1, 100);

        return [
            'part_id' => Part::factory(),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            // Kept consistent with quantity * unit_cost, the same invariant the
            // service enforces on write.
            'cost' => round($quantity * $unitCost, 2),
            'source' => PartUsageSource::Purchased->value,
            'paid_by' => PartUsagePayer::Us->value,
            'returned_quantity' => 0,
            'mistaken' => false,
        ];
    }

    /** A fixed gross cost, for tests that only care about the total. */
    public function costing(float $cost): static
    {
        return $this->state(fn () => ['quantity' => 1, 'unit_cost' => $cost, 'cost' => $cost]);
    }

    public function mistaken(): static
    {
        return $this->state(fn () => ['mistaken' => true]);
    }
}
