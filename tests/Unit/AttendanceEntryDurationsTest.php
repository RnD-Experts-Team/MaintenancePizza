<?php

namespace Tests\Unit;

use App\Enums\AttendanceEventKind;
use App\Models\AttendanceEntry;
use App\Models\AttendanceEvent;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Attendance events arrive in whatever order somebody remembers them, and
 * nothing upstream constrains them, so durations() has to survive whatever was
 * typed. These tests pin the defensive rules: never negative, never thrown,
 * always warned.
 *
 * Still a UNIT test. The events are built in memory and set as the relation, so
 * durations() never touches a database -- which is also a useful guarantee in
 * its own right, since this method is called once per entry per pay run.
 */
class AttendanceEntryDurationsTest extends TestCase
{
    /**
     * A session built from a flat list of `kind => time`.
     *
     * Ordered by time on the way in, exactly as the real walk does, so a test
     * can list events in whatever order reads best.
     *
     * @param  array<int, array{0: AttendanceEventKind, 1: string}>  $events
     */
    private function entry(array $events): AttendanceEntry
    {
        $entry = new AttendanceEntry();

        $entry->setRelation('events', new Collection(array_map(
            fn (array $pair) => new AttendanceEvent(['kind' => $pair[0], 'at' => $pair[1], 'mistaken' => false]),
            $events,
        )));

        return $entry;
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
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 16:30:00'],
        ])->durations();

        $this->assertSame(510, $d['work']);
        $this->assertSame([], $d['warnings']);
    }

    public function test_a_break_inside_the_shift_is_deducted_from_work_but_reported_in_full(): void
    {
        $d = $this->entry([
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::BreakStart, '2026-09-10 12:00:00'],
            [AttendanceEventKind::BreakEnd, '2026-09-10 12:30:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 16:00:00'],
        ])->durations();

        $this->assertSame(450, $d['work']);
        $this->assertSame(30, $d['break']);
    }

    /**
     * THE THING THE OLD SHAPE COULD NOT DO.
     *
     * Four fixed column pairs meant one break per session, so a second one
     * needed a second entry -- which then looked like a second visit and, on a
     * pay sheet, like a second lot of hours to reconcile.
     */
    public function test_a_session_can_hold_several_breaks(): void
    {
        $d = $this->entry([
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::BreakStart, '2026-09-10 10:00:00'],
            [AttendanceEventKind::BreakEnd, '2026-09-10 10:15:00'],
            [AttendanceEventKind::BreakStart, '2026-09-10 13:00:00'],
            [AttendanceEventKind::BreakEnd, '2026-09-10 13:30:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 16:00:00'],
        ])->durations();

        $this->assertSame(45, $d['break']);
        // 480 minutes on the clock, minus both breaks.
        $this->assertSame(435, $d['work']);
        $this->assertSame([], $d['warnings']);
    }

    public function test_several_parts_runs_and_travels_in_one_session(): void
    {
        $d = $this->entry([
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::TravelStart, '2026-09-10 08:00:00'],
            [AttendanceEventKind::TravelEnd, '2026-09-10 08:30:00'],
            [AttendanceEventKind::PartsRunStart, '2026-09-10 10:00:00'],
            [AttendanceEventKind::PartsRunEnd, '2026-09-10 10:45:00'],
            [AttendanceEventKind::PartsRunStart, '2026-09-10 14:00:00'],
            [AttendanceEventKind::PartsRunEnd, '2026-09-10 14:20:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 16:00:00'],
        ])->durations();

        $this->assertSame(30, $d['travel']);
        $this->assertSame(65, $d['parts_run']);
        $this->assertSame([], $d['warnings']);
    }

    /**
     * A parts run recorded after clock-out must not eat into work time.
     */
    public function test_a_parts_run_outside_the_shift_is_not_deducted(): void
    {
        $d = $this->entry([
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 12:00:00'],
            [AttendanceEventKind::PartsRunStart, '2026-09-10 13:00:00'],
            [AttendanceEventKind::PartsRunEnd, '2026-09-10 14:00:00'],
        ])->durations();

        $this->assertSame(240, $d['work']);
        $this->assertSame(60, $d['parts_run']);
    }

    public function test_only_the_overlapping_portion_of_a_straddling_interval_is_deducted(): void
    {
        $d = $this->entry([
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            // Half inside the shift, half after it.
            [AttendanceEventKind::TravelStart, '2026-09-10 11:30:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 12:00:00'],
            [AttendanceEventKind::TravelEnd, '2026-09-10 12:30:00'],
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
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::BreakStart, '2026-09-10 09:00:00'],
            [AttendanceEventKind::PartsRunStart, '2026-09-10 09:30:00'],
            [AttendanceEventKind::BreakEnd, '2026-09-10 10:00:00'],
            [AttendanceEventKind::PartsRunEnd, '2026-09-10 10:30:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 12:00:00'],
        ])->durations();

        // 08:00-12:00 minus the merged 09:00-10:30 block = 150 minutes.
        $this->assertSame(150, $d['work']);
        $this->assertSame(60, $d['break']);
        $this->assertSame(60, $d['parts_run']);
    }

    public function test_an_inverted_pair_counts_zero_and_warns(): void
    {
        $d = $this->entry([
            [AttendanceEventKind::ClockIn, '2026-09-10 16:00:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 08:00:00'],
        ])->durations();

        $this->assertSame(0, $d['work']);
        $this->assertContains('inverted_pair:work', $d['warnings']);
    }

    /**
     * AN OPEN SESSION IS NOT AN ERROR — this is the rule that changed.
     *
     * Under the old shape a clock-in with no clock-out emitted
     * incomplete_pair:work, which meant somebody currently on the clock looked
     * like a mistake. It is also why the previous UI stopped writing one record
     * per event: doing so manufactured that warning on every single record.
     */
    public function test_an_open_session_does_not_warn(): void
    {
        $d = $this->entry([
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
        ])->durations();

        $this->assertSame(0, $d['work']);
        $this->assertSame([], $d['warnings']);
    }

    /**
     * Once the session is CLOSED, a half-recorded span really is something
     * somebody forgot, and that is worth saying.
     */
    public function test_a_dangling_half_warns_once_the_session_is_closed(): void
    {
        $d = $this->entry([
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::BreakStart, '2026-09-10 12:00:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 16:00:00'],
        ])->durations();

        $this->assertSame(480, $d['work']);
        $this->assertSame(0, $d['break']);
        $this->assertContains('incomplete_pair:break', $d['warnings']);
    }

    /** A closing half with nothing open counts zero and says so. */
    public function test_a_close_with_nothing_open_warns(): void
    {
        $d = $this->entry([
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::BreakEnd, '2026-09-10 12:00:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 16:00:00'],
        ])->durations();

        $this->assertSame(480, $d['work']);
        $this->assertContains('incomplete_pair:break', $d['warnings']);
    }

    public function test_an_implausible_pair_is_clamped_to_24_hours_and_warned(): void
    {
        $d = $this->entry([
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::ClockOut, '2026-09-15 08:00:00'],
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
            [AttendanceEventKind::ClockIn, '2026-09-10 22:00:00'],
            [AttendanceEventKind::ClockOut, '2026-09-11 06:00:00'],
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
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::BreakStart, '2026-09-10 08:00:00'],
            [AttendanceEventKind::TravelStart, '2026-09-10 08:00:00'],
            [AttendanceEventKind::BreakEnd, '2026-09-10 09:00:00'],
            [AttendanceEventKind::TravelEnd, '2026-09-10 09:00:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 09:00:00'],
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
            [AttendanceEventKind::TravelStart, '2026-09-10 07:00:00'],
            [AttendanceEventKind::TravelEnd, '2026-09-10 08:15:00'],
        ])->durations();

        $this->assertSame(0, $d['work']);
        $this->assertSame(75, $d['travel']);
        $this->assertSame([], $d['warnings']);
    }

    /**
     * Events are walked by TIME, not by insertion order.
     *
     * A coordinator catching up on a visit may enter the clock-out before
     * remembering the break, and the session still has to add up.
     */
    public function test_events_entered_out_of_order_still_add_up(): void
    {
        $d = $this->entry([
            [AttendanceEventKind::ClockOut, '2026-09-10 16:00:00'],
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::BreakEnd, '2026-09-10 12:30:00'],
            [AttendanceEventKind::BreakStart, '2026-09-10 12:00:00'],
        ])->durations();

        $this->assertSame(450, $d['work']);
        $this->assertSame(30, $d['break']);
        $this->assertSame([], $d['warnings']);
    }

    /** A struck event stops counting, while staying in the ledger. */
    public function test_a_mistaken_event_is_not_counted(): void
    {
        $entry = $this->entry([
            [AttendanceEventKind::ClockIn, '2026-09-10 08:00:00'],
            [AttendanceEventKind::BreakStart, '2026-09-10 12:00:00'],
            [AttendanceEventKind::BreakEnd, '2026-09-10 12:30:00'],
            [AttendanceEventKind::ClockOut, '2026-09-10 16:00:00'],
        ]);

        // Strike both halves of the break: it never happened.
        $entry->events[1]->mistaken = true;
        $entry->events[2]->mistaken = true;

        $d = $entry->durations();

        $this->assertSame(480, $d['work']);
        $this->assertSame(0, $d['break']);
        $this->assertSame([], $d['warnings']);
    }
}
