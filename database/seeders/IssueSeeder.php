<?php

namespace Database\Seeders;

use App\Models\Issue;
use Illuminate\Database\Seeder;

class IssueSeeder extends Seeder
{
    public function run(): void
    {
        $issues = [
            ['title' => 'Oven', 'description' => 'Oven not heating or malfunctioning'],
            ['title' => 'Making Table', 'description' => 'Prep / making table issues'],
            ['title' => 'Walk-in Cooler', 'description' => 'Cooler temperature problems'],
            ['title' => 'POS System', 'description' => null],
            ['title' => 'Sink / Plumbing', 'description' => null],
            ['title' => 'Lighting', 'description' => null],
        ];

        foreach ($issues as $issue) {
            Issue::firstOrCreate(['title' => $issue['title']], ['description' => $issue['description']]);
        }
    }
}
