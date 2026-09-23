<?php

namespace Database\Factories;

use App\Models\AttendanceEntry;
use App\Models\Technician;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceEntry>
 */
class AttendanceEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'technician_id' => Technician::factory(),
            'start_clock' => null,
            'end_clock' => null,

            'mistaken' => false,
        ];
    }
}
