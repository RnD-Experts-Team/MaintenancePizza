<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Refrigeration', 'Cooking Equipment', 'Electrical', 'Plumbing', 'HVAC'] as $name) {
            Category::firstOrCreate(['name' => $name]);
        }
    }
}
