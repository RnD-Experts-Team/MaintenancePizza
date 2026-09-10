<?php

namespace Tests\Feature;

use App\Enums\PartUsagePayer;
use App\Enums\PartUsageSource;
use App\Models\DailyPayEntry;
use App\Models\Part;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\DailyPayEntryService;
use App\Services\WorkflowRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a payment gathers from the records that already exist, how it splits
 * that between stores, and what it refuses to count twice.
 */
class DailyPayAggregationTest extends TestCase
{
    use RefreshDatabase;

    private Technician $technician;

    private Store $storeA;

    private Store $storeB;

    private TicketIssue $issueA1;

    private TicketIssue $issueA2;

    private TicketIssue $issueB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician = Technician::factory()->create();
        $this->storeA = Store::factory()->create();
        $this->storeB = Store::factory()->create();

        $ticketA = Ticket::factory()->create(['store_id' => $this->storeA->id]);
        $this->issueA1 = TicketIssue::factory()->for($ticketA)->create();
        $this->issueA2 = TicketIssue::factory()->for($ticketA)->create();
        $this->issueB = TicketIssue::factory()->for(Ticket::factory()->create(['store_id' => $this->storeB->id]))->create();

        $this->technician->ticketIssues()->attach([$this->issueA1->id, $this->issueA2->id, $this->issueB->id]);
    }

    private function service(): DailyPayEntryService
    {
        return app(DailyPayEntryService::class);
    }

    private function workflow(): WorkflowRecordService
    {
        return app(WorkflowRecordService::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function pay(array $lines, array $paymentOverrides = []): array
    {
        return $this->service()->create([
            'date' => '2026-09-10',
            'payments' => [array_replace([
                'technician_id' => $this->technician->id,
                'hourly_payment_rate' => 20.0000,
                'lines' => $lines,
            ], $paymentOverrides)],
        ]);
    }

    private function attendance(array $issueIds, array $clocks): void
    {
        $this->workflow()->createAttendance(array_merge([
            'technician_id' => $this->technician->id,
            'ticket_issue_ids' => $issueIds,
        ], $clocks), []);
    }

    private function technicianPaidPart(array $issueIds, string $quantity, string $unitCost, array $extra = []): array
    {
        return $this->workflow()->createPartUsage(array_merge([
            'ticket_issue_ids' => $issueIds,
            'part_id' => Part::factory()->create()->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'source' => PartUsageSource::Purchased->value,
            'paid_by' => PartUsagePayer::Technician->value,
            'paid_by_technician_id' => $this->technician->id,
        ], $extra), []);
    }

    public function test_hours_are_gathered_from_attendance_when_the_line_does_not_state_them(): void
    {
        $this->attendance([$this->issueA1->id], [
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 16:00:00',
            'start_break' => '2026-09-10 12:00:00',
            'end_break' => '2026-09-10 12:30:00',
            'start_travel' => '2026-09-10 07:00:00',
            'end_travel' => '2026-09-10 08:00:00',
        ]);

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'ticket_issue_ids' => [$this->issueA1->id]],
        ]);

        $payment = $result['payments'][0];
        $line = $payment['lines'][0];

        // 8h clocked minus the 30m break inside it.
        $this->assertSame('7.50', (string) $line['gathered']['work_hours']);
        $this->assertSame('1.00', (string) $line['gathered']['travel_hours']);
        $this->assertSame('0.50', (string) $line['gathered']['break_hours']);

        // Gathered figures were copied onto the payable fields.
        $this->assertSame('7.50', (string) $line['total_working_hours']);
        $this->assertFalse($line['hours_overridden']);

        // (7.5 work + 1 travel) x 20 = 170. Break is not paid.
        $this->assertSame('170.00', (string) $line['line_total']);
    }

    /**
     * The whole point of hours_overridden: a hand-corrected line must survive
     * a recalculate untouched.
     */
    public function test_recalculate_refreshes_gathered_lines_but_leaves_overridden_ones_alone(): void
    {
        $this->attendance([$this->issueA1->id], [
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 16:00:00',
        ]);
        $this->attendance([$this->issueB->id], [
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 11:00:00',
        ]);

        $result = $this->pay([
            // Hand-corrected.
            ['store_id' => $this->storeA->id, 'total_working_hours' => 2.00, 'ticket_issue_ids' => [$this->issueA1->id]],
            // Gathered.
            ['store_id' => $this->storeB->id, 'ticket_issue_ids' => [$this->issueB->id]],
        ]);

        $this->assertTrue($result['payments'][0]['lines'][0]['hours_overridden']);
        $this->assertSame('2.00', (string) $result['payments'][0]['lines'][0]['total_working_hours']);
        $this->assertSame('3.00', (string) $result['payments'][0]['lines'][1]['total_working_hours']);

        // More attendance turns up for store B.
        $this->attendance([$this->issueB->id], [
            'start_clock' => '2026-09-10 13:00:00',
            'end_clock' => '2026-09-10 15:00:00',
        ]);

        $recalculated = $this->service()->recalculate(DailyPayEntry::findOrFail($result['id']));
        $lines = $recalculated['payments'][0]['lines'];

        // The hand-corrected line is untouched...
        $this->assertSame('2.00', (string) $lines[0]['total_working_hours']);
        // ...but its gathered figure still reports what the records say.
        $this->assertSame('8.00', (string) $lines[0]['gathered']['work_hours']);
        // The gathered line picked up the new entry.
        $this->assertSame('5.00', (string) $lines[1]['total_working_hours']);
    }

    /**
     * One attendance entry linked to two issues of the same store must be
     * counted once, not once per issue.
     */
    public function test_an_attendance_entry_spanning_issues_is_counted_once(): void
    {
        $this->attendance([$this->issueA1->id, $this->issueA2->id], [
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 16:00:00',
        ]);

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'ticket_issue_ids' => [$this->issueA1->id, $this->issueA2->id]],
        ]);

        $this->assertSame('8.00', (string) $result['payments'][0]['gathered']['work_hours']);
        $this->assertSame(1, DB::table('daily_pay_payment_attendance_entry')->count());
    }

    public function test_reimbursable_parts_are_gathered_and_ours_are_not(): void
    {
        $this->technicianPaidPart([$this->issueA1->id], '2', '30.00');

        // One we paid for ourselves — not reimbursable.
        $this->workflow()->createPartUsage([
            'ticket_issue_ids' => [$this->issueA1->id],
            'part_id' => Part::factory()->create()->id,
            'quantity' => '1',
            'unit_cost' => '99.00',
            'source' => PartUsageSource::Purchased->value,
            'paid_by' => PartUsagePayer::Us->value,
        ], []);

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'total_working_hours' => 0, 'ticket_issue_ids' => [$this->issueA1->id]],
        ]);

        $payment = $result['payments'][0];

        $this->assertSame('60.00', (string) $payment['gathered']['reimbursable_parts']);
        $this->assertSame('60.00', (string) $payment['lines'][0]['gathered']['reimbursable_parts']);
        $this->assertSame('60.00', (string) $payment['total_amount']);
    }

    /** A part handed back to storage is only reimbursed for what was kept. */
    public function test_returned_parts_are_reimbursed_at_net_cost(): void
    {
        $shelf = \App\Models\StorageLocation::factory()->create();

        $this->technicianPaidPart([$this->issueA1->id], '10', '5.00', [
            'returned_quantity' => '4',
            'returned_to_storage_location_id' => $shelf->id,
        ]);

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'total_working_hours' => 0, 'ticket_issue_ids' => [$this->issueA1->id]],
        ]);

        // Gross 50, four handed back at 5 => 30 reimbursed.
        $this->assertSame('30.00', (string) $result['payments'][0]['gathered']['reimbursable_parts']);
    }

    /**
     * One receipt covering issues at two stores is attributed whole to one
     * line, never split, and counted once at payment level.
     */
    public function test_a_part_usage_spanning_stores_is_attributed_once_and_warned_about(): void
    {
        $this->technicianPaidPart([$this->issueA1->id, $this->issueA2->id, $this->issueB->id], '1', '90.00');

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'total_working_hours' => 0, 'ticket_issue_ids' => [$this->issueA1->id, $this->issueA2->id]],
            ['store_id' => $this->storeB->id, 'total_working_hours' => 0, 'ticket_issue_ids' => [$this->issueB->id]],
        ]);

        $payment = $result['payments'][0];

        $this->assertSame('90.00', (string) $payment['gathered']['reimbursable_parts']);
        // Store A owns two of the three covered issues, so it takes the whole.
        $this->assertSame('90.00', (string) $payment['lines'][0]['gathered']['reimbursable_parts']);
        $this->assertSame('0.00', (string) $payment['lines'][1]['gathered']['reimbursable_parts']);

        $this->assertContains('part_usage_spans_stores', array_column($payment['aggregation_warnings'], 'code'));

        // Counted once at payment level, so the total is 90 and not 180.
        $this->assertSame('90.00', (string) $payment['total_amount']);
        $this->assertSame(1, DB::table('daily_pay_payment_part_usage')->count());
    }

    /**
     * Work with no store to pin it to still counts, at payment level. This is
     * what the payment-level fields exist for.
     */
    public function test_parts_on_an_other_store_ticket_count_at_payment_level_only(): void
    {
        $otherStoreTicket = Ticket::factory()->otherStore('Warehouse 5')->create();
        $issue = TicketIssue::factory()->for($otherStoreTicket)->create();
        $this->technician->ticketIssues()->attach($issue->id);

        $this->technicianPaidPart([$issue->id], '1', '75.00');

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'total_working_hours' => 0, 'ticket_issue_ids' => [$issue->id]],
        ]);

        $payment = $result['payments'][0];

        $this->assertSame('75.00', (string) $payment['gathered']['reimbursable_parts']);
        $this->assertSame('75.00', (string) $payment['total_amount']);
    }

    /** Two clock windows overlapping is flagged, never silently de-duplicated. */
    public function test_overlapping_attendance_is_warned_about_not_removed(): void
    {
        $this->attendance([$this->issueA1->id], [
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 12:00:00',
        ]);
        $this->attendance([$this->issueA1->id], [
            'start_clock' => '2026-09-10 11:00:00',
            'end_clock' => '2026-09-10 15:00:00',
        ]);

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'ticket_issue_ids' => [$this->issueA1->id]],
        ]);

        $payment = $result['payments'][0];

        $this->assertContains('overlapping_entries', array_column($payment['aggregation_warnings'], 'code'));
        // Both are still counted — a human decides, not the gather.
        $this->assertSame('8.00', (string) $payment['gathered']['work_hours']);
    }

    public function test_attendance_recorded_on_another_date_is_counted_but_flagged(): void
    {
        $this->attendance([$this->issueA1->id], [
            'start_clock' => '2026-09-11 22:00:00',
            'end_clock' => '2026-09-12 02:00:00',
        ]);

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'ticket_issue_ids' => [$this->issueA1->id]],
        ]);

        $payment = $result['payments'][0];

        $this->assertSame('4.00', (string) $payment['gathered']['work_hours']);
        $this->assertContains('entry_outside_date', array_column($payment['aggregation_warnings'], 'code'));
    }

    public function test_a_mistaken_part_usage_is_not_reimbursed(): void
    {
        $usage = $this->technicianPaidPart([$this->issueA1->id], '1', '40.00');
        $this->workflow()->markPartUsageMistaken(\App\Models\PartUsage::findOrFail($usage['id']));

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'total_working_hours' => 0, 'ticket_issue_ids' => [$this->issueA1->id]],
        ]);

        $this->assertSame('0.00', (string) $result['payments'][0]['gathered']['reimbursable_parts']);
    }

    public function test_a_mistaken_attendance_entry_is_not_counted(): void
    {
        $entry = $this->workflow()->createAttendance([
            'technician_id' => $this->technician->id,
            'ticket_issue_ids' => [$this->issueA1->id],
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 16:00:00',
        ], []);

        $this->workflow()->markAttendanceMistaken(\App\Models\AttendanceEntry::findOrFail($entry['id']));

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'ticket_issue_ids' => [$this->issueA1->id]],
        ]);

        $this->assertSame('0.00', (string) $result['payments'][0]['gathered']['work_hours']);
    }

    /**
     * A receipt already claimed by another payment on the same sheet must not
     * be paid a second time.
     */
    public function test_a_part_already_claimed_on_the_same_sheet_is_skipped(): void
    {
        $other = Technician::factory()->create();
        $other->ticketIssues()->attach($this->issueA1->id);

        $usage = $this->technicianPaidPart([$this->issueA1->id], '1', '50.00');

        // Two payments on one sheet, both reaching the same issue.
        $result = $this->service()->create([
            'date' => '2026-09-10',
            'payments' => [
                [
                    'technician_id' => $this->technician->id,
                    'hourly_payment_rate' => 20.0000,
                    'lines' => [['store_id' => $this->storeA->id, 'total_working_hours' => 0, 'ticket_issue_ids' => [$this->issueA1->id]]],
                ],
                [
                    'technician_id' => $other->id,
                    'hourly_payment_rate' => 20.0000,
                    'lines' => [['store_id' => $this->storeA->id, 'total_working_hours' => 0, 'ticket_issue_ids' => [$this->issueA1->id]]],
                ],
            ],
        ]);

        // Only the payee who actually paid gets it, and only once.
        $totals = array_map(fn ($p) => (string) $p['gathered']['reimbursable_parts'], $result['payments']);
        $this->assertSame(['50.00', '0.00'], $totals);
        $this->assertSame(1, DB::table('daily_pay_payment_part_usage')->count());
        $this->assertSame((int) $usage['id'], (int) DB::table('daily_pay_payment_part_usage')->value('part_usage_id'));
    }

    /** Another payee's receipt is never gathered into this payment. */
    public function test_parts_paid_by_a_different_technician_are_not_gathered(): void
    {
        $other = Technician::factory()->create();
        $other->ticketIssues()->attach($this->issueA1->id);

        $this->workflow()->createPartUsage([
            'ticket_issue_ids' => [$this->issueA1->id],
            'part_id' => Part::factory()->create()->id,
            'quantity' => '1',
            'unit_cost' => '80.00',
            'source' => PartUsageSource::Purchased->value,
            'paid_by' => PartUsagePayer::Technician->value,
            'paid_by_technician_id' => $other->id,
        ], []);

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'total_working_hours' => 0, 'ticket_issue_ids' => [$this->issueA1->id]],
        ]);

        $this->assertSame('0.00', (string) $result['payments'][0]['gathered']['reimbursable_parts']);
    }

    /**
     * Re-gathering must not accumulate claims — that would multiply the money
     * every time somebody pressed recalculate.
     */
    public function test_recalculate_is_idempotent(): void
    {
        $this->attendance([$this->issueA1->id], [
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 16:00:00',
        ]);
        $this->technicianPaidPart([$this->issueA1->id], '1', '25.00');

        $result = $this->pay([
            ['store_id' => $this->storeA->id, 'ticket_issue_ids' => [$this->issueA1->id]],
        ]);

        $firstTotal = (string) $result['payments'][0]['total_amount'];

        $entry = DailyPayEntry::findOrFail($result['id']);
        $this->service()->recalculate($entry);
        $again = $this->service()->recalculate($entry);

        $this->assertSame($firstTotal, (string) $again['payments'][0]['total_amount']);
        $this->assertSame(1, DB::table('daily_pay_payment_attendance_entry')->count());
        $this->assertSame(1, DB::table('daily_pay_payment_part_usage')->count());
    }
}
