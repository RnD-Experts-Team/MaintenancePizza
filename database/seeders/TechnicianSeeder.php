<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Technician;
use Illuminate\Database\Seeder;

class TechnicianSeeder extends Seeder
{
    public function run(): void
    {
        $byName = Category::pluck('id', 'name');

        $technicians = [
            ['name' => 'Alice Johnson', 'category' => 'Refrigeration'],
            ['name' => 'Bob Smith', 'category' => 'Cooking Equipment'],
            ['name' => 'Carlos Diaz', 'category' => 'Electrical'],
            ['name' => 'Dana Lee', 'category' => 'HVAC'],
            ['name' => 'Independent Contractor', 'category' => null],
        ];

        foreach ($technicians as $tech) {
            Technician::firstOrCreate(
                ['name' => $tech['name']],
                ['category_id' => $tech['category'] ? $byName[$tech['category']] ?? null : null],
            );
        }
    }
}
