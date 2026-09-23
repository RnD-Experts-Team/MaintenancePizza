<?php

namespace Tests\Feature;

use App\Enums\PartUsagePayer;
use App\Enums\PartUsageSource;
use App\Models\Part;
use App\Models\PartUsage;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\DailyPayEntryService;
use App\Services\TicketIssueService;
use App\Services\TicketService;
use App\Services\WorkflowRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Being on a pay sheet IS being paid. These check the status a record reports,
 * and that the ticket filter agrees with it.
 */
class PaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    private Technician $technician;

    private Store $store;

    private Ticket $ticket;

    private TicketIssue $issue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician = Technician::factory()->create();
        $this->store = Store::factory()->create();
        $this->ticket = Ticket::factory()->create(['store_id' => $this->store->id]);
        $this->issue = TicketIssue::factory()->for($this->ticket)->create();
        $this->technician->ticketIssues()->attach($this->issue->id);
    }

    private function workflow(): WorkflowRecordService
    {
        return app(WorkflowRecordService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function attendance(): array
    {
        return $this->workflow()->createAttendance([
            'technician_id' => $this->technician->id,
            'ticket_issue_ids' => [$this->issue->id],
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 16:00:00',
        ], []);
    }

    /**
     * @return array<string, mixed>
     */
    private function partUsage(array $overrides = []): array
    {
        return $this->workflow()->createPartUsage(array_replace([
            'ticket_issue_ids' => [$this->issue->id],
            'part_id' => Part::factory()->create()->id,
            'quantity' => '2',
            'unit_cost' => '25.00',
            'source' => PartUsageSource::Purchased->value,
            'paid_by' => PartUsagePayer::Technician->value,
            'paid_by_technician_id' => $this->technician->id,
        ], $overrides), []);
    }

    private function paySheet(): void
    {
        app(DailyPayEntryService::class)->create([
            'date' => '2026-09-10',
            'payments' => [[
                'technician_id' => $this->technician->id,
                'hourly_payment_rate' => 20.0000,
                'lines' => [[
                    'store_id' => $this->store->id,
                    'ticket_issue_ids' => [$this->issue->id],
                ]],
            ]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function showIssue(): array
    {
        return app(TicketIssueService::class)->show($this->issue->fresh());
    }

    /**
     * @return array<int>
     */
    private function filter(array $query): array
    {
        return app(TicketService::class)
            ->index(Request::create('/api/tickets', 'GET', $query))
            ->getCollection()->pluck('id')->all();
    }

    public function test_work_not_yet_on_a_pay_sheet_reads_as_unpaid(): void
    {
        $this->attendance();
        $this->partUsage();

        $issue = $this->showIssue();

        $this->assertSame('unpaid', $issue['payment']['status']['value']);
        $this->assertSame('unpaid', $issue['attendance_entries'][0]['payment']['status']['value']);
        $this->assertSame('unpaid', $issue['part_usages'][0]['payment']['status']['value']);
        $this->assertSame([], $issue['attendance_entries'][0]['payment']['payments']);
    }

    public function test_once_a_pay_sheet_covers_it_everything_reads_as_paid(): void
    {
        $this->attendance();
        $this->partUsage();
        $this->paySheet();

        $issue = $this->showIssue();

        $this->assertSame('paid', $issue['payment']['status']['value']);
        $this->assertSame('Paid', $issue['payment']['status']['label']);
        $this->assertNotEmpty($issue['payment']['daily_pay_line_ids']);

        $attendance = $issue['attendance_entries'][0]['payment'];
        $this->assertSame('paid', $attendance['status']['value']);
        $this->assertCount(1, $attendance['payments']);
        $this->assertSame('2026-09-10', $attendance['payments'][0]['date']);
        $this->assertSame(480, $attendance['payments'][0]['minutes']['work']);
        $this->assertSame($this->technician->id, $attendance['payments'][0]['technician_id']);

        $part = $issue['part_usages'][0]['payment'];
        $this->assertSame('paid', $part['status']['value']);
        $this->assertSame('50.00', (string) $part['payments'][0]['amount']);
    }

    /** A part we bought ourselves is nobody's to be paid back. */
    public function test_a_part_we_paid_for_has_nothing_to_pay(): void
    {
        $this->partUsage(['paid_by' => PartUsagePayer::Us->value, 'paid_by_technician_id' => null]);

        $issue = $this->showIssue();

        $this->assertSame('not_payable', $issue['part_usages'][0]['payment']['status']['value']);
        $this->assertSame('not_payable', $issue['payment']['status']['value']);
    }

    public function test_a_mistaken_record_has_nothing_to_pay(): void
    {
        $entry = $this->attendance();
        $this->workflow()->markAttendanceMistaken(\App\Models\AttendanceEntry::findOrFail($entry['id']));

        $this->assertSame('not_payable', $this->showIssue()['attendance_entries'][0]['payment']['status']['value']);
    }

    /** Anything still owed dominates the roll-up. */
    public function test_an_issue_stays_unpaid_while_any_one_payable_is_outstanding(): void
    {
        $this->attendance();
        $this->paySheet();

        // A receipt that turns up after the sheet was written.
        $this->partUsage();

        $issue = $this->showIssue();

        $this->assertSame('paid', $issue['attendance_entries'][0]['payment']['status']['value']);
        $this->assertSame('unpaid', $issue['part_usages'][0]['payment']['status']['value']);
        $this->assertSame('unpaid', $issue['payment']['status']['value']);
    }

    // ------------------------------------------------------------- The filter

    public function test_the_ticket_filter_finds_what_is_still_owed(): void
    {
        $this->attendance();

        $this->assertContains($this->ticket->id, $this->filter(['payment_statuses' => ['unpaid']]));
        $this->assertNotContains($this->ticket->id, $this->filter(['payment_statuses' => ['paid']]));

        $this->paySheet();

        $this->assertNotContains($this->ticket->id, $this->filter(['payment_statuses' => ['unpaid']]));
        $this->assertContains($this->ticket->id, $this->filter(['payment_statuses' => ['paid']]));
    }

    public function test_a_ticket_with_nothing_payable_is_neither_paid_nor_unpaid(): void
    {
        // No attendance, and the only part was ours.
        $this->partUsage(['paid_by' => PartUsagePayer::Us->value, 'paid_by_technician_id' => null]);

        $this->assertNotContains($this->ticket->id, $this->filter(['payment_statuses' => ['unpaid']]));
        $this->assertNotContains($this->ticket->id, $this->filter(['payment_statuses' => ['paid']]));
        $this->assertContains($this->ticket->id, $this->filter(['payment_statuses' => ['not_payable']]));
    }

    /** A ticket is only "paid" once every payable on it is settled. */
    public function test_a_partly_settled_ticket_is_reported_as_unpaid_not_paid(): void
    {
        $this->attendance();
        $this->paySheet();
        $this->partUsage();

        $this->assertContains($this->ticket->id, $this->filter(['payment_statuses' => ['unpaid']]));
        $this->assertNotContains($this->ticket->id, $this->filter(['payment_statuses' => ['paid']]));
    }

    public function test_the_filter_ors_several_statuses_together(): void
    {
        $paidTicket = $this->ticket;
        $this->attendance();
        $this->paySheet();

        // A second ticket, still owed.
        $otherTicket = Ticket::factory()->create(['store_id' => $this->store->id]);
        $otherIssue = TicketIssue::factory()->for($otherTicket)->create();
        $this->technician->ticketIssues()->attach($otherIssue->id);
        $this->workflow()->createAttendance([
            'technician_id' => $this->technician->id,
            'ticket_issue_ids' => [$otherIssue->id],
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 10:00:00',
        ], []);

        $both = $this->filter(['payment_statuses' => ['paid', 'unpaid']]);

        $this->assertContains($paidTicket->id, $both);
        $this->assertContains($otherTicket->id, $both);
        $this->assertSame([$otherTicket->id], $this->filter(['payment_statuses' => ['unpaid']]));
    }

    /** Mistaken records must not keep a ticket looking unpaid forever. */
    public function test_a_mistaken_payable_does_not_hold_a_ticket_in_unpaid(): void
    {
        $usage = $this->partUsage();
        PartUsage::findOrFail($usage['id'])->update(['mistaken' => true]);

        $this->assertNotContains($this->ticket->id, $this->filter(['payment_statuses' => ['unpaid']]));
    }
}
