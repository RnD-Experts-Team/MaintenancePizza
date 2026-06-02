<?php

namespace Database\Seeders;

use App\Models\Part;
use Illuminate\Database\Seeder;

class PartSeeder extends Seeder
{
    public function run(): void
    {
        $parts = [
            'Heating Element',
            'Thermostat',
            'Compressor',
            'Door Gasket',
            'Control Board',
            'Fan Motor',
        ];

        foreach ($parts as $name) {
            Part::firstOrCreate(['name' => $name]);
        }
    }
}
