<?php

namespace Database\Factories;

use App\Models\Part;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use App\Models\StorageLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovementLine>
 */
class StockMovementLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_movement_id' => StockMovement::factory(),
            'part_id' => Part::factory(),
            'storage_location_id' => StorageLocation::factory(),
            'quantity' => 1,
            'direction' => 1,
            'unit_cost' => null,
            'total_cost' => null,
        ];
    }
}
