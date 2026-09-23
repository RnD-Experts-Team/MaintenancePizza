<?php

namespace Database\Factories;

use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StorageLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBalance>
 */
class StockBalanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'part_id' => Part::factory(),
            'storage_location_id' => StorageLocation::factory(),
            'quantity' => 0,
        ];
    }
}
