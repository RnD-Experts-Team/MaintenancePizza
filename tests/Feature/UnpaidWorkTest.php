<?php

namespace Tests\Feature;

use App\Models\AttendanceEntry;
use App\Models\DailyPayEntry;
use App\Models\Part;
use App\Models\PartUsage;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\DailyPayEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What has been recorded but never put on a pay sheet.
 *
 * Being on a pay sheet IS being paid in this system, so an unclaimed record is
 * money still owed. The fact was derivable per record and per issue and was
 * nowhere rolled up, so "what do we owe Ahmad" meant opening tickets until you
 * were satisfied you had found them all.
 */
class UnpaidWorkTest extends TestCase
{
    use RefreshDatabase;

    private Technician $technician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->technician = Technician::factory()->create(['name' => 'Ahmad']);
    }

    private function logVisit(?Technician $for = null): AttendanceEntry
    {
        return AttendanceEntry::factory()->create([
            'technician_id' => ($for ?? $this->technician)->id,
            'start_clock' => '2026-01-01 09:00:00',
            'end_clock' => '2026-01-01 17:00:00',
        ]);
    }

    private function claim(AttendanceEntry $entry): void
    {
        $payEntry = DailyPayEntry::create(['date' => '2026-01-01']);
        $paymentId = DB::table('daily_pay_payments')->insertGetId([
            'daily_pay_entry_id' => $payEntry->id,
            'technician_id' => $entry->technician_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('daily_pay_payment_attendance_entry')->insert([
            'daily_pay_payment_id' => $paymentId,
            'attendance_entry_id' => $entry->id,
            'work_minutes' => 0,
            'travel_minutes' => 0,
            'break_minutes' => 0,
            'parts_run_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(array $filters = []): array
    {
        return app(DailyPayEntryService::class)->unpaidWork($filters);
    }

    public function test_an_unclaimed_visit_shows_as_owed(): void
    {
        $this->logVisit();

        $result = $this->summary();

        $this->assertCount(1, $result['technicians']);
        $this->assertSame($this->technician->id, $result['technicians'][0]['technician_id']);
        $this->assertSame('Ahmad', $result['technicians'][0]['technician']['name']);
        $this->assertSame(1, $result['technicians'][0]['unpaid_visits']);
    }

    public function test_a_claimed_visit_does_not(): void
    {
        $entry = $this->logVisit();
        $this->claim($entry);

        $this->assertCount(0, $this->summary()['technicians']);
    }

    /** A mistaken record is not a debt -- it never happened. */
    public function test_a_mistaken_visit_is_not_owed(): void
    {
        AttendanceEntry::factory()->create([
            'technician_id' => $this->technician->id,
            'start_clock' => '2026-01-01 09:00:00',
            'mistaken' => true,
        ]);

        $this->assertCount(0, $this->summary()['technicians']);
    }

    /** Never clocked in: nothing to pay for, and it would otherwise sit here
     *  forever as an entry that can never be settled. */
    public function test_an_entry_with_no_clock_in_is_not_owed(): void
    {
        AttendanceEntry::factory()->create([
            'technician_id' => $this->technician->id,
            'start_clock' => null,
        ]);

        $this->assertCount(0, $this->summary()['technicians']);
    }

    /**
     * Only parts somebody ELSE paid for are a debt. `paid_by = us` was our own
     * money and was never owed to anyone.
     */
    public function test_only_parts_the_technician_paid_for_are_owed(): void
    {
        $ticket = Ticket::factory()->create();
        $issue = TicketIssue::factory()->for($ticket)->create();

        $theirs = PartUsage::factory()->create([
            'part_id' => Part::factory(),
            'quantity' => '2',
            'unit_cost' => '10.0000',
            'cost' => '20.00',
            'returned_quantity' => '0',
            'paid_by' => 'technician',
            'paid_by_technician_id' => $this->technician->id,
        ]);
        $theirs->ticketIssues()->attach($issue->id);

        $ours = PartUsage::factory()->create([
            'part_id' => Part::factory(),
            'quantity' => '5',
            'unit_cost' => '10.0000',
            'cost' => '50.00',
            'paid_by' => 'us',
        ]);
        $ours->ticketIssues()->attach($issue->id);

        $row = $this->summary()['technicians'][0];
        $this->assertSame(1, $row['unpaid_part_count']);
        $this->assertSame('20.00', $row['unpaid_part_amount']);
    }

    /** Reimbursed at what they are actually out of pocket, after returns. */
    public function test_returns_reduce_what_is_owed(): void
    {
        $ticket = Ticket::factory()->create();
        $issue = TicketIssue::factory()->for($ticket)->create();

        $usage = PartUsage::factory()->create([
            'part_id' => Part::factory(),
            'quantity' => '5',
            'unit_cost' => '10.0000',
            'cost' => '50.00',
            'returned_quantity' => '2',
            'paid_by' => 'technician',
            'paid_by_technician_id' => $this->technician->id,
        ]);
        $usage->ticketIssues()->attach($issue->id);

        $this->assertSame('30.00', $this->summary()['technicians'][0]['unpaid_part_amount']);
    }

    public function test_it_can_be_narrowed_to_one_payee(): void
    {
        $other = Technician::factory()->create();
        $this->logVisit();
        $this->logVisit($other);

        $this->assertCount(2, $this->summary()['technicians']);
        $this->assertCount(1, $this->summary(['technician_ids' => [$other->id]])['technicians']);
    }

    public function test_the_totals_add_up(): void
    {
        $other = Technician::factory()->create();
        $this->logVisit();
        $this->logVisit();
        $this->logVisit($other);

        $totals = $this->summary()['totals'];
        $this->assertSame(2, $totals['technicians_owed']);
        $this->assertSame(3, $totals['unpaid_visits']);
    }
}
