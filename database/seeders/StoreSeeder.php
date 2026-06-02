<?php

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['03795-00001', '03795-00002', '03795-00003'] as $number) {
            Store::firstOrCreate(['store_number' => $number]);
        }
    }
}
