<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with dev data + a printed API token.
     */
    public function run(): void
    {
        $this->call([
            DevUserSeeder::class,
            StoreSeeder::class,
            CategorySeeder::class,
            TechnicianSeeder::class,
            IssueSeeder::class,
            PartSeeder::class,
        ]);
    }
}
