<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'moved_at' => now(),
            'type' => StockMovementType::Purchase->value,
            'storage_location_id' => StorageLocation::factory(),
            'paid_by' => null,
            'paid_by_technician_id' => null,
            'body' => null,
            'mistaken' => false,
        ];
    }
}
