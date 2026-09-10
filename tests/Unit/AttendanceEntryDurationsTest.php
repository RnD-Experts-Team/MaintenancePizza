<?php

namespace Tests\Unit;

use App\Models\AttendanceEntry;
use Tests\TestCase;

/**
 * The attendance clock columns are deliberately unconstrained, so durations()
 * has to survive whatever the dispatcher typed. These tests pin the defensive
 * rules: never negative, never thrown, always warned.
 */
class AttendanceEntryDurationsTest extends TestCase
{
    private function entry(array $clocks): AttendanceEntry
    {
        return new AttendanceEntry($clocks);
    }

    public function test_nothing_recorded_yields_zeroes_and_no_warnings(): void
    {
        $d = $this->entry([])->durations();

        $this->assertSame(
            ['work' => 0, 'travel' => 0, 'break' => 0, 'parts_run' => 0, 'warnings' => []],
            $d
        );
    }

    public function test_a_plain_shift_reports_its_full_length(): void
    {
        $d = $this->entry([
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 16:30:00',
        ])->durations();

        $this->assertSame(510, $d['work']);
        $this->assertSame([], $d['warnings']);
    }

    public function test_a_break_inside_the_shift_is_deducted_from_work_but_reported_in_full(): void
    {
        $d = $this->entry([
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 16:00:00',
            'start_break' => '2026-09-10 12:00:00',
            'end_break' => '2026-09-10 12:30:00',
        ])->durations();

        $this->assertSame(450, $d['work']);
        $this->assertSame(30, $d['break']);
    }

    /**
     * A parts run recorded after clock-out must not eat into work time.
     */
    public function test_a_parts_run_outside_the_shift_is_not_deducted(): void
    {
        $d = $this->entry([
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 12:00:00',
            'start_parts_run' => '2026-09-10 13:00:00',
            'end_parts_run' => '2026-09-10 14:00:00',
        ])->durations();

        $this->assertSame(240, $d['work']);
        $this->assertSame(60, $d['parts_run']);
    }

    public function test_only_the_overlapping_portion_of_a_straddling_interval_is_deducted(): void
    {
        $d = $this->entry([
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 12:00:00',
            // Half inside the shift, half after it.
            'start_travel' => '2026-09-10 11:30:00',
            'end_travel' => '2026-09-10 12:30:00',
        ])->durations();

        $this->assertSame(210, $d['work']);
        $this->assertSame(60, $d['travel']);
    }

    /**
     * The overlap between a break and a parts run must be deducted once, not
     * twice — blind subtraction would report 120 minutes of work here.
     */
    public function test_overlapping_deductions_are_merged_before_subtracting(): void
    {
        $d = $this->entry([
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 12:00:00',
            'start_break' => '2026-09-10 09:00:00',
            'end_break' => '2026-09-10 10:00:00',
            'start_parts_run' => '2026-09-10 09:30:00',
            'end_parts_run' => '2026-09-10 10:30:00',
        ])->durations();

        // 08:00-12:00 minus the merged 09:00-10:30 block = 150 minutes.
        $this->assertSame(150, $d['work']);
        $this->assertSame(60, $d['break']);
        $this->assertSame(60, $d['parts_run']);
    }

    public function test_an_inverted_pair_counts_zero_and_warns(): void
    {
        $d = $this->entry([
            'start_clock' => '2026-09-10 16:00:00',
            'end_clock' => '2026-09-10 08:00:00',
        ])->durations();

        $this->assertSame(0, $d['work']);
        $this->assertContains('inverted_pair:work', $d['warnings']);
    }

    public function test_a_half_clocked_pair_counts_zero_and_warns(): void
    {
        $d = $this->entry(['start_clock' => '2026-09-10 08:00:00'])->durations();

        $this->assertSame(0, $d['work']);
        $this->assertContains('incomplete_pair:work', $d['warnings']);
    }

    public function test_an_implausible_pair_is_clamped_to_24_hours_and_warned(): void
    {
        $d = $this->entry([
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-15 08:00:00',
        ])->durations();

        $this->assertSame(1440, $d['work']);
        $this->assertContains('implausible_pair:work', $d['warnings']);
    }

    /**
     * A shift crossing midnight is normal, not an error.
     */
    public function test_an_overnight_shift_is_handled(): void
    {
        $d = $this->entry([
            'start_clock' => '2026-09-10 22:00:00',
            'end_clock' => '2026-09-11 06:00:00',
        ])->durations();

        $this->assertSame(480, $d['work']);
        $this->assertSame([], $d['warnings']);
    }

    /**
     * Deductions larger than the shift itself must floor at zero, never go
     * negative — payroll reads this number.
     */
    public function test_work_never_goes_negative(): void
    {
        $d = $this->entry([
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 09:00:00',
            'start_break' => '2026-09-10 08:00:00',
            'end_break' => '2026-09-10 09:00:00',
            'start_travel' => '2026-09-10 08:00:00',
            'end_travel' => '2026-09-10 09:00:00',
        ])->durations();

        $this->assertSame(0, $d['work']);
    }

    /**
     * With no clock window there is nothing to subtract from, but the other
     * buckets are still reported — a standalone travel entry is legitimate.
     */
    public function test_travel_only_entry_reports_travel_and_zero_work(): void
    {
        $d = $this->entry([
            'start_travel' => '2026-09-10 07:00:00',
            'end_travel' => '2026-09-10 08:15:00',
        ])->durations();

        $this->assertSame(0, $d['work']);
        $this->assertSame(75, $d['travel']);
        $this->assertSame([], $d['warnings']);
    }
}
