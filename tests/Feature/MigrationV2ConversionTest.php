<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\StorageLocation;
use App\Models\Store;
use App\Models\Technician;
use App\Services\MigrationV2\AttendanceToTrips;
use App\Services\MigrationV2\DiagnosesAndAssignments;
use App\Services\MigrationV2\PartUsagesToV2;
use App\Services\MigrationV2\PayToPayments;
use App\Services\MigrationV2\UniqueNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The v2 data conversion (migration 2026_11_01_000060), run over LEGACY rows
 * written straight into the old tables the way the old app left them. The
 * hard rule is that no money, time or note is lost or counted twice, and
 * that a second run changes nothing.
 */
class MigrationV2ConversionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private array $id = [];

    private const NOW = '2026-10-10 12:00:00';

    // ------------------------------------------------------------ Seeding

    private function insert(string $table, array $row): int
    {
        $defaults = in_array($table, ['attendance_entry_ticket_issue', 'diagnosis_ticket_issue', 'assignment_ticket_issue', 'part_ticket_issue',
            'pay_entry_ticket_issue', 'technician_ticket_issue', 'daily_pay_line_ticket_issue', 'notes', 'daily_pay_entries',
            'daily_pay_payments', 'daily_pay_lines', 'daily_pay_payment_attendance_entry', 'daily_pay_payment_part_usage',
            'attendance_entries', 'attendance_events', 'diagnoses', 'assignments', 'assignment_delays', 'pay_entries',
            'part_usages', 'stock_movements', 'tickets', 'ticket_issues'], true)
            ? ['created_at' => self::NOW, 'updated_at' => self::NOW]
            : [];

        return (int) DB::table($table)->insertGetId($row + $defaults);
    }

    private function note(string $type, int $id, string $body): int
    {
        return $this->insert('notes', ['notable_type' => $type, 'notable_id' => $id, 'body' => $body]);
    }

    private function seedLegacy(): void
    {
        $a = Store::factory()->create(['id' => 101, 'store_number' => '03795-00001']);
        $b = Store::factory()->create(['id' => 102, 'store_number' => '03795-00002']);
        $tom = $this->id['tom'] = Technician::factory()->create(['name' => 'Tom'])->id;
        $ray = $this->id['ray'] = Technician::factory()->create(['name' => 'Ray'])->id;
        $belt = $this->id['belt'] = Part::factory()->create(['name' => 'Belt'])->id;
        $fuse = Part::factory()->create(['name' => 'Fuse'])->id;
        $shelf = StorageLocation::factory()->create()->id;

        $k1 = $this->insert('tickets', ['store_id' => $a->id]);
        $k2 = $this->insert('tickets', ['store_id' => $b->id]);
        $issue = fn (int $ticket, string $status) => $this->insert('ticket_issues', [
            'ticket_id' => $ticket, 'other_title' => 'Walk-in cooler', 'priority' => 'medium', 'description' => 'Broken', 'status' => $status,
        ]);
        $ti1 = $this->id['ti1'] = $issue($k1, 'closed');
        $ti2 = $this->id['ti2'] = $issue($k1, 'scheduled');
        $ti3 = $this->id['ti3'] = $issue($k2, 'scheduled');

        // Who was on which issue: Tom alone on ti1, both on ti2, Ray on ti3.
        foreach ([[$tom, $ti1], [$tom, $ti2], [$ray, $ti2], [$ray, $ti3]] as [$t, $i]) {
            $this->insert('technician_ticket_issue', ['technician_id' => $t, 'ticket_issue_id' => $i]);
        }

        // ---- Attendance
        // E1: Tom, clocked out at noon and back in, a break, a drive, and a
        // parts run typed by mistake; worked at A (ti1, ti2) and B (ti3).
        $e1 = $this->id['e1'] = $this->insert('attendance_entries', [
            'technician_id' => $tom, 'start_clock' => '2026-10-05 08:00:00', 'end_clock' => '2026-10-05 17:00:00',
        ]);
        foreach ([
            ['clock_in', '2026-10-05 08:00:00'], ['break_start', '2026-10-05 10:00:00'], ['break_end', '2026-10-05 10:30:00'],
            ['clock_out', '2026-10-05 12:00:00'], ['clock_in', '2026-10-05 13:00:00'], ['travel_start', '2026-10-05 13:10:00'],
            ['travel_end', '2026-10-05 13:40:00'], ['clock_out', '2026-10-05 17:00:00'],
        ] as [$kind, $at]) {
            $this->insert('attendance_events', ['attendance_entry_id' => $e1, 'kind' => $kind, 'at' => $at]);
        }
        $this->insert('attendance_events', ['attendance_entry_id' => $e1, 'kind' => 'parts_run_start', 'at' => '2026-10-05 14:00:00', 'mistaken' => true]);
        foreach ([$ti1, $ti2, $ti3] as $i) {
            $this->insert('attendance_entry_ticket_issue', ['attendance_entry_id' => $e1, 'ticket_issue_id' => $i]);
        }
        $this->id['e1_note'] = $this->note('App\\Models\\AttendanceEntry', $e1, 'Came back after lunch');

        // E2: Ray, still on the clock. E3: Ray, a plain morning.
        $e2 = $this->id['e2'] = $this->insert('attendance_entries', ['technician_id' => $ray, 'start_clock' => '2026-10-06 09:00:00']);
        $this->insert('attendance_events', ['attendance_entry_id' => $e2, 'kind' => 'clock_in', 'at' => '2026-10-06 09:00:00']);
        $this->insert('attendance_entry_ticket_issue', ['attendance_entry_id' => $e2, 'ticket_issue_id' => $ti3]);

        $e3 = $this->id['e3'] = $this->insert('attendance_entries', ['technician_id' => $ray, 'start_clock' => '2026-10-05 08:00:00', 'end_clock' => '2026-10-05 11:00:00']);
        $this->insert('attendance_events', ['attendance_entry_id' => $e3, 'kind' => 'clock_in', 'at' => '2026-10-05 08:00:00']);
        $this->insert('attendance_events', ['attendance_entry_id' => $e3, 'kind' => 'clock_out', 'at' => '2026-10-05 11:00:00']);
        $this->insert('attendance_entry_ticket_issue', ['attendance_entry_id' => $e3, 'ticket_issue_id' => $ti2]);

        // ---- Diagnoses: one on two issues with a note; one on none.
        $d1 = $this->id['d1'] = $this->insert('diagnoses', ['body' => 'Belt worn through']);
        $this->insert('diagnosis_ticket_issue', ['diagnosis_id' => $d1, 'ticket_issue_id' => $ti1]);
        $this->insert('diagnosis_ticket_issue', ['diagnosis_id' => $d1, 'ticket_issue_id' => $ti2]);
        $this->note('App\\Models\\Diagnosis', $d1, 'Photo of the belt in the files');
        $this->id['d2'] = $this->insert('diagnoses', ['body' => 'Orphan']);

        // ---- Bookings (no technician of their own yet).
        $as1 = $this->id['as1'] = $this->insert('assignments', ['assigned_date' => '2026-10-07', 'assigned_hour' => '09:00:00']);
        $this->insert('assignment_ticket_issue', ['assignment_id' => $as1, 'ticket_issue_id' => $ti2]);
        $this->insert('assignment_delays', ['assignment_id' => $as1, 'old_date' => '2026-10-06', 'new_date' => '2026-10-07', 'reason' => 'Rain']);
        $this->note('App\\Models\\Assignment', $as1, 'Bring the ladder');
        $as2 = $this->id['as2'] = $this->insert('assignments', ['assigned_date' => '2026-10-08']);
        $this->insert('assignment_ticket_issue', ['assignment_id' => $as2, 'ticket_issue_id' => $ti1]);
        $this->id['as3'] = $this->insert('assignments', ['assigned_date' => '2026-10-09']);

        // ---- Part usages
        $usage = fn (array $row) => $this->insert('part_usages', $row + [
            'part_id' => $belt, 'quantity' => 1, 'unit_cost' => 0, 'cost' => 0, 'source' => 'purchased', 'paid_by' => 'us', 'returned_quantity' => 0,
        ]);
        $this->id['pu_storage'] = $usage(['quantity' => 2, 'unit_cost' => 5, 'cost' => 10, 'source' => 'from_storage', 'storage_location_id' => $shelf]);
        $this->id['pu_us'] = $usage(['part_id' => $fuse, 'quantity' => 2, 'unit_cost' => 20, 'cost' => 40]);
        $this->id['pu_reimb'] = $usage(['cost' => 25, 'unit_cost' => 25, 'paid_by' => 'technician', 'paid_by_technician_id' => $tom]);
        $this->id['pu_unpaid'] = $usage(['cost' => 12.5, 'unit_cost' => 12.5, 'paid_by' => 'technician']);
        $this->id['pu_unknown'] = $usage(['cost' => 9, 'unit_cost' => 9, 'paid_by' => 'technician']);
        foreach ([['pu_storage', $ti1], ['pu_us', $ti3], ['pu_reimb', $ti1], ['pu_unpaid', $ti1], ['pu_unknown', $ti2]] as [$key, $i]) {
            $this->insert('part_ticket_issue', ['part_usage_id' => $this->id[$key], 'ticket_issue_id' => $i]);
        }
        $this->note('App\\Models\\PartUsage', $this->id['pu_reimb'], 'Receipt from the hardware store');
        $this->id['used_movement'] = $this->insert('stock_movements', [
            'moved_at' => '2026-10-05 09:00:00', 'type' => 'used', 'storage_location_id' => $shelf, 'part_usage_id' => $this->id['pu_storage'],
        ]);

        // ---- Daily Pay
        $sheet = $this->insert('daily_pay_entries', ['date' => '2026-10-05']);
        $this->note('App\\Models\\DailyPayEntry', $sheet, 'Paid in the office');

        // P1, Tom, by the hour: line at A (hours + gas + money owed), a
        // fixed-price line at B, gas and money owed for the day, a parts
        // refund. 75 + 10 + 5 + 150 + 7 + 3 + 25 = 275.
        $p1 = $this->id['p1'] = $this->insert('daily_pay_payments', [
            'daily_pay_entry_id' => $sheet, 'technician_id' => $tom, 'hourly_payment_rate' => 20, 'gas' => 7, 'money_owed' => 3, 'total_amount' => 275,
        ]);
        $l1 = $this->insert('daily_pay_lines', [
            'daily_pay_entry_id' => $sheet, 'daily_pay_payment_id' => $p1, 'technician_id' => $tom, 'store_id' => $a->id,
            'total_working_hours' => 3, 'travel_time' => 0.5, 'parts_run_time' => 0.25, 'gas' => 10, 'money_owed' => 5,
        ]);
        $l2 = $this->insert('daily_pay_lines', [
            'daily_pay_entry_id' => $sheet, 'daily_pay_payment_id' => $p1, 'technician_id' => $tom, 'store_id' => $b->id, 'lump_sum' => 150,
        ]);
        $this->insert('daily_pay_line_ticket_issue', ['daily_pay_line_id' => $l1, 'ticket_issue_id' => $ti1]);
        $this->insert('daily_pay_line_ticket_issue', ['daily_pay_line_id' => $l2, 'ticket_issue_id' => $ti3]);
        $this->note('App\\Models\\DailyPayLine', $l1, 'Hours checked against the clock');
        $this->insert('daily_pay_payment_attendance_entry', [
            'daily_pay_payment_id' => $p1, 'attendance_entry_id' => $e1, 'daily_pay_line_id' => $l1,
            'work_minutes' => 180, 'travel_minutes' => 30, 'break_minutes' => 30, 'parts_run_minutes' => 15,
        ]);
        $this->insert('daily_pay_payment_part_usage', [
            'daily_pay_payment_id' => $p1, 'part_usage_id' => $this->id['pu_reimb'], 'daily_pay_line_id' => $l1, 'amount' => 25,
        ]);

        // P2, Ray, "one price for the day" over two stores, gas on a line,
        // money owed for the day: 200 + 4 + 6 = 210. His morning (E3) was
        // claimed on the fixed-price day.
        $p2 = $this->id['p2'] = $this->insert('daily_pay_payments', [
            'daily_pay_entry_id' => $sheet, 'technician_id' => $ray, 'lump_sum' => 200, 'money_owed' => 6, 'total_amount' => 210,
        ]);
        $l3 = $this->insert('daily_pay_lines', [
            'daily_pay_entry_id' => $sheet, 'daily_pay_payment_id' => $p2, 'technician_id' => $ray, 'store_id' => $a->id, 'total_working_hours' => 2, 'gas' => 4,
        ]);
        $l4 = $this->insert('daily_pay_lines', [
            'daily_pay_entry_id' => $sheet, 'daily_pay_payment_id' => $p2, 'technician_id' => $ray, 'store_id' => $b->id, 'total_working_hours' => 3,
        ]);
        $this->insert('daily_pay_line_ticket_issue', ['daily_pay_line_id' => $l3, 'ticket_issue_id' => $ti2]);
        $this->insert('daily_pay_line_ticket_issue', ['daily_pay_line_id' => $l4, 'ticket_issue_id' => $ti3]);
        $this->insert('daily_pay_payment_attendance_entry', [
            'daily_pay_payment_id' => $p2, 'attendance_entry_id' => $e3, 'daily_pay_line_id' => $l4, 'work_minutes' => 180,
        ]);

        // A second sheet: 3.33 h × 17.50 = 58.275, which the old sheet
        // rounded to 58.28; and a payment whose lines fall 10 short of its
        // recorded total.
        $sheet2 = $this->insert('daily_pay_entries', ['date' => '2026-10-08']);
        $p3 = $this->id['p3'] = $this->insert('daily_pay_payments', [
            'daily_pay_entry_id' => $sheet2, 'technician_id' => $tom, 'total_amount' => 58.28,
        ]);
        $this->insert('daily_pay_lines', [
            'daily_pay_entry_id' => $sheet2, 'daily_pay_payment_id' => $p3, 'technician_id' => $tom, 'store_id' => $a->id,
            'total_working_hours' => 3.33, 'hourly_payment_rate' => 17.5,
        ]);
        $p4 = $this->id['p4'] = $this->insert('daily_pay_payments', [
            'daily_pay_entry_id' => $sheet2, 'technician_id' => $ray, 'hourly_payment_rate' => 20, 'total_amount' => 50,
        ]);
        $this->insert('daily_pay_lines', [
            'daily_pay_entry_id' => $sheet2, 'daily_pay_payment_id' => $p4, 'technician_id' => $ray, 'store_id' => $b->id, 'total_working_hours' => 2,
        ]);

        // ---- One-off pay entries
        $pe1 = $this->id['pe1'] = $this->insert('pay_entries', [
            'technician_id' => $tom, 'base_pay' => 50, 'performance_pay' => 10, 'miles_driven' => 20, 'per_mile_rate' => 0.5, 'driving_base_pay' => 5,
        ]);
        $this->insert('pay_entry_ticket_issue', ['pay_entry_id' => $pe1, 'ticket_issue_id' => $ti1]);
        $this->id['pe2'] = $this->insert('pay_entries', ['technician_id' => $ray, 'base_pay' => 30, 'mistaken' => true]);
    }

    private function convert(): void
    {
        app(AttendanceToTrips::class)->run();
        app(DiagnosesAndAssignments::class)->run();
        app(PayToPayments::class)->run();
        app(PartUsagesToV2::class)->run();
    }

    private function newId(string $legacyTable, string $key, string $newTable): int
    {
        $id = DB::table('migration_v2_map')
            ->where('legacy_table', $legacyTable)->where('legacy_id', $this->id[$key])->where('new_table', $newTable)
            ->orderBy('id')->value('new_id');
        $this->assertNotNull($id, "{$legacyTable} {$key} was not mapped to {$newTable}");

        return (int) $id;
    }

    /** @return list<array<string, mixed>> */
    private function linesOf(int $paymentId): array
    {
        return DB::table('payment_lines')->where('payment_id', $paymentId)->orderBy('sort_order')->get()
            ->map(fn ($l) => [
                'type' => $l->type,
                'store_id' => $l->store_id === null ? null : (int) $l->store_id,
                'amount' => number_format((float) $l->amount, 2, '.', ''),
                'description' => $l->description,
                'issues' => DB::table('payment_line_ticket_issue')->where('payment_line_id', $l->id)->orderBy('ticket_issue_id')->pluck('ticket_issue_id')->map(fn ($i) => (int) $i)->all(),
            ])->all();
    }

    private function total(int $paymentId): string
    {
        return number_format((float) DB::table('payments')->where('id', $paymentId)->value('total'), 2, '.', '');
    }

    // ------------------------------------------------------------- Tests

    public function test_payments_keep_every_cent_and_type_the_lines_as_documented(): void
    {
        $this->seedLegacy();
        $this->convert();

        $p1 = $this->newId('daily_pay_payments', 'p1', 'payments');
        $this->assertSame('275.00', $this->total($p1));
        $this->assertSame([
            ['type' => 'hourly', 'store_id' => 101, 'amount' => '75.00', 'description' => null, 'issues' => [$this->id['ti1']]],
            ['type' => 'gas', 'store_id' => 101, 'amount' => '10.00', 'description' => null, 'issues' => [$this->id['ti1']]],
            ['type' => 'other', 'store_id' => 101, 'amount' => '5.00', 'description' => 'Money owed', 'issues' => [$this->id['ti1']]],
            ['type' => 'fixed', 'store_id' => 102, 'amount' => '150.00', 'description' => null, 'issues' => [$this->id['ti3']]],
            ['type' => 'gas', 'store_id' => 101, 'amount' => '7.00', 'description' => 'Gas for the day', 'issues' => []],
            ['type' => 'other', 'store_id' => 101, 'amount' => '3.00', 'description' => 'Money owed', 'issues' => []],
            ['type' => 'parts', 'store_id' => 101, 'amount' => '25.00', 'description' => 'Parts the technician paid for', 'issues' => [$this->id['ti1']]],
        ], $this->linesOf($p1));
        $hourly = DB::table('payment_lines')->where('payment_id', $p1)->where('type', 'hourly')->first();
        $this->assertEquals(3.75, (float) $hourly->hours);
        $this->assertEquals(20, (float) $hourly->rate);
        $payment = DB::table('payments')->find($p1);
        $this->assertSame((int) $this->id['tom'], (int) $payment->technician_id);
        $this->assertNull($payment->method);
        $this->assertStringStartsWith('2026-10-05', (string) $payment->paid_at);

        // "One price for the day" replaces the labour of both stores.
        $p2 = $this->newId('daily_pay_payments', 'p2', 'payments');
        $this->assertSame('210.00', $this->total($p2));
        $this->assertSame([
            ['type' => 'inspection', 'store_id' => 101, 'amount' => '200.00', 'description' => 'One price for the day, covering 2 stores', 'issues' => [$this->id['ti2'], $this->id['ti3']]],
            ['type' => 'gas', 'store_id' => 101, 'amount' => '4.00', 'description' => null, 'issues' => [$this->id['ti2']]],
            ['type' => 'other', 'store_id' => 101, 'amount' => '6.00', 'description' => 'Money owed', 'issues' => []],
        ], $this->linesOf($p2));

        // Rounded like the old sheet, not truncated.
        $p3 = $this->newId('daily_pay_payments', 'p3', 'payments');
        $this->assertSame('58.28', $this->total($p3));
        $this->assertSame(['hourly'], array_column($this->linesOf($p3), 'type'));

        // Lines short of the old total: an adjustment line, and the report says so.
        $p4 = $this->newId('daily_pay_payments', 'p4', 'payments');
        $this->assertSame('50.00', $this->total($p4));
        $this->assertSame(['hourly', 'other'], array_column($this->linesOf($p4), 'type'));
        $this->assertSame('10.00', $this->linesOf($p4)[1]['amount']);
        $this->assertSame('Adjusted to match the old pay sheet', $this->linesOf($p4)[1]['description']);
        $this->assertTrue(DB::table('migration_v2_report')->where('step', 'payments')->where('level', 'warning')->where('message', 'like', '%daily pay payment #' . $this->id['p4'] . '%')->exists());

        // Every converted payment equals its old total.
        foreach (['p1', 'p2', 'p3', 'p4'] as $key) {
            $this->assertSame(
                number_format((float) DB::table('daily_pay_payments')->where('id', $this->id[$key])->value('total_amount'), 2, '.', ''),
                $this->total($this->newId('daily_pay_payments', $key, 'payments')),
            );
        }

        // A pay entry: base + performance is a fixed price, driving pay is gas.
        $pe1 = $this->newId('pay_entries', 'pe1', 'payments');
        $this->assertSame('75.00', $this->total($pe1));
        $this->assertSame([
            ['type' => 'fixed', 'store_id' => 101, 'amount' => '60.00', 'description' => 'Base 50.00 + performance 10.00', 'issues' => [$this->id['ti1']]],
            ['type' => 'gas', 'store_id' => 101, 'amount' => '15.00', 'description' => 'Driving pay', 'issues' => [$this->id['ti1']]],
        ], $this->linesOf($pe1));
        $pe2 = DB::table('payments')->find($this->newId('pay_entries', 'pe2', 'payments'));
        $this->assertEquals(1, $pe2->mistaken);
        $this->assertSame('Unknown store', DB::table('payment_lines')->where('payment_id', $pe2->id)->value('other_store'));

        // Notes follow: the line's note onto its payment, the sheet's note
        // copied onto every payment of the sheet.
        $this->assertSame(1, DB::table('notes')->where('notable_type', 'App\\Models\\Payment')->where('notable_id', $p1)->where('body', 'Hours checked against the clock')->count());
        foreach ([$p1, $p2] as $paymentId) {
            $this->assertSame(1, DB::table('notes')->where('notable_type', 'App\\Models\\Payment')->where('notable_id', $paymentId)->where('body', 'Paid in the office')->count());
        }
    }

    public function test_attendance_becomes_trips_sessions_events_and_stops(): void
    {
        $this->seedLegacy();
        $this->convert();

        // E1: one closed trip, two sessions (clocked out and back in).
        $trip = DB::table('trips')->find($this->newId('attendance_entries', 'e1', 'trips'));
        $this->assertSame('closed', $trip->status);
        $this->assertSame((int) $this->id['tom'], (int) $trip->technician_id);
        $this->assertStringStartsWith('2026-10-05', (string) $trip->planned_date);
        $this->assertSame('2026-10-05 17:00:00', (string) $trip->closed_at);

        $sessions = DB::table('trip_sessions')->where('trip_id', $trip->id)->orderBy('clock_in')->get();
        $this->assertSame([['2026-10-05 08:00:00', '2026-10-05 12:00:00'], ['2026-10-05 13:00:00', '2026-10-05 17:00:00']],
            $sessions->map(fn ($s) => [(string) $s->clock_in, (string) $s->clock_out])->all());
        $this->assertSame(['migrated'], $sessions->pluck('source')->unique()->values()->all());
        $this->assertSame([null, null], $sessions->pluck('open_marker')->all());

        // Each event lands in the session it happened in; mistaken kept, flagged.
        $events = fn ($session) => DB::table('trip_events')->where('trip_session_id', $session->id)->orderBy('at')->get()
            ->map(fn ($e) => [$e->kind, (bool) $e->mistaken])->all();
        $this->assertSame([['break_start', false], ['break_end', false]], $events($sessions[0]));
        $this->assertSame([['travel_start', false], ['travel_end', false], ['parts_run_start', true]], $events($sessions[1]));

        // One stop per store, the issues on it, done when the issue is closed.
        $stops = DB::table('trip_stops')->where('trip_id', $trip->id)->orderBy('sort_order')->get();
        $this->assertSame([101, 102], $stops->pluck('store_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['done', 'done'], $stops->pluck('status')->all());
        $this->assertSame(
            [[$this->id['ti1'], 'done'], [$this->id['ti2'], 'pending']],
            DB::table('trip_stop_issues')->where('trip_stop_id', $stops[0]->id)->orderBy('ticket_issue_id')->get()->map(fn ($r) => [(int) $r->ticket_issue_id, $r->task_status])->all(),
        );
        $this->assertSame(1, DB::table('trip_stop_issues')->where('trip_stop_id', $stops[1]->id)->count());

        // The entry's note moved to the trip.
        $note = DB::table('notes')->find($this->id['e1_note']);
        $this->assertSame(['App\\Models\\Trip', (int) $trip->id], [$note->notable_type, (int) $note->notable_id]);

        // E2: still on the clock -- an open trip with the live session.
        $open = DB::table('trips')->find($this->newId('attendance_entries', 'e2', 'trips'));
        $this->assertSame('open', $open->status);
        $this->assertNull($open->closed_at);
        $live = DB::table('trip_sessions')->where('trip_id', $open->id)->sole();
        $this->assertNull($live->clock_out);
        $this->assertEquals(1, $live->open_marker);
        $this->assertSame('arrived', DB::table('trip_stops')->where('trip_id', $open->id)->value('status'));

        // Sessions are linked to the payment that paid them, once each.
        $p1Hourly = DB::table('payment_lines')->where('payment_id', $this->newId('daily_pay_payments', 'p1', 'payments'))->where('type', 'hourly')->value('id');
        $this->assertSame(
            [[(int) $sessions[0]->id, 225], [(int) $sessions[1]->id, 0]],
            DB::table('payment_line_trip_session')->where('payment_line_id', $p1Hourly)->orderBy('trip_session_id')->get()->map(fn ($r) => [(int) $r->trip_session_id, (int) $r->paid_minutes])->all(),
        );
        // Ray's morning was paid by the day's one price, not left unpaid.
        $e3Session = $this->newId('attendance_entries', 'e3', 'trip_sessions');
        $p2Price = DB::table('payment_lines')->where('payment_id', $this->newId('daily_pay_payments', 'p2', 'payments'))->where('type', 'inspection')->value('id');
        $this->assertSame((int) $p2Price, (int) DB::table('payment_line_trip_session')->where('trip_session_id', $e3Session)->value('payment_line_id'));
        $this->assertSame(3, DB::table('payment_line_trip_session')->count());
    }

    public function test_diagnoses_become_inspections_and_bookings_get_technicians(): void
    {
        $this->seedLegacy();
        $this->convert();

        $inspections = DB::table('issue_inspections')->orderBy('ticket_issue_id')->get();
        $this->assertCount(2, $inspections);
        $this->assertSame([$this->id['ti1'], $this->id['ti2']], $inspections->pluck('ticket_issue_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(['done', 'done'], $inspections->pluck('status')->all());
        $this->assertSame(['Belt worn through', 'Belt worn through'], $inspections->pluck('findings')->all());
        // The issue's sole technician; nobody when two shared it.
        $this->assertSame((int) $this->id['tom'], (int) $inspections[0]->technician_id);
        $this->assertNull($inspections[1]->technician_id);
        // Each inspection has the diagnosis's note; none is left behind.
        foreach ($inspections as $inspection) {
            $this->assertSame(1, DB::table('notes')->where('notable_type', 'App\\Models\\IssueInspection')->where('notable_id', $inspection->id)->where('body', 'Photo of the belt in the files')->count());
        }
        $this->assertSame(0, DB::table('notes')->where('notable_type', 'App\\Models\\Diagnosis')->count());
        $this->assertTrue(DB::table('migration_v2_report')->where('step', 'inspections')->where('message', 'like', '%#' . $this->id['d2'] . ' %')->exists());

        // AS1 covered two technicians: one booking each, same issue, same history.
        $copies = DB::table('assignment_ticket_issue')->where('ticket_issue_id', $this->id['ti2'])->orderBy('assignment_id')->pluck('assignment_id')->map(fn ($i) => (int) $i)->all();
        $this->assertSame((int) $this->id['as1'], $copies[0]);
        $this->assertCount(2, $copies);
        $bookings = DB::table('assignments')->whereIn('id', $copies)->orderBy('id')->get();
        $this->assertSame([(int) $this->id['tom'], (int) $this->id['ray']], $bookings->pluck('technician_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(['2026-10-07', '2026-10-07'], $bookings->map(fn ($b) => substr((string) $b->assigned_date, 0, 10))->all());
        foreach ($copies as $bookingId) {
            $this->assertSame([$this->id['ti2']], DB::table('assignment_ticket_issue')->where('assignment_id', $bookingId)->pluck('ticket_issue_id')->map(fn ($i) => (int) $i)->all());
            $this->assertSame(1, DB::table('assignment_delays')->where('assignment_id', $bookingId)->where('reason', 'Rain')->count());
            $this->assertSame(1, DB::table('notes')->where('notable_type', 'App\\Models\\Assignment')->where('notable_id', $bookingId)->where('body', 'Bring the ladder')->count());
        }

        $this->assertSame((int) $this->id['tom'], (int) DB::table('assignments')->where('id', $this->id['as2'])->value('technician_id'));
        $this->assertNull(DB::table('assignments')->where('id', $this->id['as3'])->value('technician_id'));
        $this->assertTrue(DB::table('migration_v2_report')->where('step', 'bookings')->where('level', 'warning')->exists());
    }

    public function test_part_usages_split_by_what_they_really_were(): void
    {
        $this->seedLegacy();
        $this->convert();

        // From storage: the Used movement now names its issue.
        $this->assertSame((int) $this->id['ti1'], (int) DB::table('stock_movements')->where('id', $this->id['used_movement'])->value('ticket_issue_id'));

        // Bought by us: a parts payment to "Unknown (migrated)".
        $payment = DB::table('payments')->find($this->newId('part_usages', 'pu_us', 'payments'));
        $unknown = DB::table('suppliers')->where('name', 'Unknown (migrated)')->sole();
        $this->assertSame((int) $unknown->id, (int) $payment->supplier_id);
        $this->assertNull($payment->technician_id);
        $this->assertSame('40.00', $this->total($payment->id));
        $line = DB::table('payment_lines')->where('payment_id', $payment->id)->sole();
        $this->assertSame(['parts', 102, (int) $unknown->id], [$line->type, (int) $line->store_id, (int) $line->bought_from_supplier_id]);
        $this->assertEquals(2, (float) $line->quantity);
        $this->assertSame([$this->id['ti3']], DB::table('payment_line_ticket_issue')->where('payment_line_id', $line->id)->pluck('ticket_issue_id')->map(fn ($i) => (int) $i)->all());

        // Paid back on a sheet: a PAID purchase, its receipt note with it.
        $paid = DB::table('technician_purchases')->find($this->newId('part_usages', 'pu_reimb', 'technician_purchases'));
        $partsLine = DB::table('payment_lines')->where('payment_id', $this->newId('daily_pay_payments', 'p1', 'payments'))->where('type', 'parts')->value('id');
        $this->assertSame((int) $partsLine, (int) $paid->payment_line_id);
        $this->assertSame([(int) $this->id['tom'], (int) $this->id['ti1'], 'Belt', '25.00'], [(int) $paid->technician_id, (int) $paid->ticket_issue_id, $paid->description, number_format((float) $paid->total_cost, 2)]);
        $this->assertSame(1, DB::table('notes')->where('notable_type', 'App\\Models\\TechnicianPurchase')->where('notable_id', $paid->id)->count());

        // Never paid back: an UNPAID purchase of the issue's only technician.
        $unpaid = DB::table('technician_purchases')->find($this->newId('part_usages', 'pu_unpaid', 'technician_purchases'));
        $this->assertNull($unpaid->payment_line_id);
        $this->assertSame((int) $this->id['tom'], (int) $unpaid->technician_id);
        $this->assertSame('12.50', number_format((float) $unpaid->total_cost, 2));

        // Nobody to owe it to: left out, reported as an error.
        $this->assertFalse(DB::table('migration_v2_map')->where('legacy_table', 'part_usages')->where('legacy_id', $this->id['pu_unknown'])->where('new_table', 'technician_purchases')->exists());
        $this->assertSame(1, DB::table('migration_v2_report')->where('step', 'parts')->where('level', 'error')->count());
        $this->assertSame(2, DB::table('technician_purchases')->count());
    }

    public function test_running_twice_creates_nothing_new(): void
    {
        $this->seedLegacy();
        $this->convert();

        $tables = [
            'trips', 'trip_sessions', 'trip_events', 'trip_stops', 'trip_stop_issues', 'issue_inspections', 'assignments',
            'assignment_ticket_issue', 'assignment_delays', 'payments', 'payment_lines', 'payment_line_ticket_issue',
            'payment_line_trip_session', 'technician_purchases', 'suppliers', 'notes', 'attachments', 'migration_v2_map', 'migration_v2_report',
        ];
        $counts = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $before = $counts();
        $totals = DB::table('payments')->orderBy('id')->pluck('total')->all();

        $this->convert();

        $this->assertSame($before, $counts());
        $this->assertSame($totals, DB::table('payments')->orderBy('id')->pluck('total')->all());
    }

    public function test_unique_names_keep_the_oldest_and_never_take_a_real_name(): void
    {
        // Rows as the old schema left them: no key yet, names colliding.
        $row = fn (string $name) => DB::table('parts')->insertGetId(['name' => $name, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $pump = $row('Pump');
        $dup = $row('  PUMP ');
        $real = $row('Pump (2)');
        $spaced = $row('Drain   cleaner');
        $deleted = DB::table('parts')->insertGetId(['name' => 'pump', 'deleted_at' => self::NOW]);

        app(UniqueNames::class)->fill('parts');

        $names = DB::table('parts')->pluck('name', 'id');
        $this->assertSame('Pump', $names[$pump]);
        // "Pump (2)" was really somebody's name, so the duplicate skips it.
        $this->assertSame('Pump (2)', $names[$real]);
        $this->assertSame('PUMP (3)', $names[$dup]);
        $this->assertSame('pump (4)', $names[$deleted]);
        $this->assertSame('Drain cleaner', $names[$spaced]);
        $this->assertSame('drain cleaner', DB::table('parts')->where('id', $spaced)->value('name_key'));
        $this->assertSame(DB::table('parts')->count(), DB::table('parts')->distinct()->count('name_key'));

        $this->assertSame(2, DB::table('migration_v2_report')->where('step', 'unique_names')->where('level', 'warning')->count());
        // Only tidied (spaces): info, not a warning.
        $this->assertSame(1, DB::table('migration_v2_report')->where('step', 'unique_names')->where('level', 'info')->count());
    }
}
