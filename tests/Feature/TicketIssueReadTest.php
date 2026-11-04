<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\IssueApproval;
use App\Models\IssueInfoRequest;
use App\Models\IssueInspection;
use App\Models\Note;
use App\Models\Part;
use App\Models\PartUnit;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\TechnicianPurchase;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripStop;
use App\Models\TripStopIssue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The full read of an issue (docs/MAINTENANCE.md §2 "Read"): money from
 * payment lines, purchases and stock; visits from trips; one merged timeline;
 * and no query per issue on a ticket's issue list.
 */
class TicketIssueReadTest extends IssueWorkflowTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function line(Payment $payment, string $type, string $amount, array $attrs = [], ?TicketIssue $issue = null): PaymentLine
    {
        $line = PaymentLine::query()->create($attrs + ['payment_id' => $payment->id, 'type' => $type, 'store_id' => $this->store->id, 'amount' => $amount]);
        $line->ticketIssues()->attach(($issue ?? $this->issue)->id);

        return $line;
    }

    private function seedMoney(?TicketIssue $issue = null): void
    {
        $issue ??= $this->issue;

        $tech = Payment::query()->create(['paid_at' => '2026-10-20', 'technician_id' => $this->technician->id, 'invoice_number' => 'INV-7', 'total' => 0]);
        $this->line($tech, 'hourly', '150.00', ['hours' => 3, 'rate' => 50], $issue);
        $this->line($tech, 'fixed', '100.00', [], $issue);
        $this->line($tech, 'inspection', '75.00', [], $issue);
        $this->line($tech, 'gas', '12.50', [], $issue);
        $this->line($tech, 'other', '5.00', [], $issue);
        $tech->attachments()->create(['path' => 'attachments/inv7.pdf', 'original_name' => 'inv7.pdf']);

        $supplier = Supplier::query()->firstOrCreate(['name' => 'Parts Town']);
        $parts = Payment::query()->create(['paid_at' => '2026-10-21', 'supplier_id' => $supplier->id, 'total' => 0]);
        $this->line($parts, 'parts', '40.00', ['quantity' => 2, 'unit_cost' => 20], $issue);

        // A mistaken payment counts for nothing.
        $mistaken = Payment::query()->create(['paid_at' => '2026-10-22', 'technician_id' => $this->technician->id, 'total' => 0, 'mistaken' => true]);
        $this->line($mistaken, 'fixed', '999.00', [], $issue);

        // A purchase not paid yet, one already paid back, one mistaken.
        TechnicianPurchase::query()->create(['technician_id' => $this->technician->id, 'ticket_issue_id' => $issue->id, 'description' => 'Fuse', 'total_cost' => 8.25, 'bought_at' => '2026-10-21 10:00:00']);
        TechnicianPurchase::query()->create(['technician_id' => $this->technician->id, 'ticket_issue_id' => $issue->id, 'description' => 'Tape', 'total_cost' => 3, 'bought_at' => '2026-10-21 11:00:00', 'payment_line_id' => $parts->lines()->first()->id]);
        TechnicianPurchase::query()->create(['technician_id' => $this->technician->id, 'ticket_issue_id' => $issue->id, 'description' => 'Oops', 'total_cost' => 50, 'bought_at' => '2026-10-21 12:00:00', 'mistaken' => true]);

        // Stock used from storage on this issue, at the line's cost; an
        // undone use counts for nothing.
        $location = StorageLocation::factory()->create(['name' => 'Main shelf']);
        $part = Part::query()->where('name', 'Relay R-12')->first() ?? Part::factory()->create(['name' => 'Relay R-12']);
        $used = StockMovement::query()->create(['moved_at' => '2026-10-23 09:00:00', 'type' => 'used', 'storage_location_id' => $location->id, 'ticket_issue_id' => $issue->id]);
        $usedLine = StockMovementLine::query()->create(['stock_movement_id' => $used->id, 'part_id' => $part->id, 'storage_location_id' => $location->id, 'quantity' => 3, 'direction' => -1, 'unit_cost' => 4.5, 'total_cost' => 13.5]);
        $unit = PartUnit::query()->create(['part_id' => $part->id, 'serial_number' => 'SN-'.$issue->id, 'status' => 'installed', 'ticket_issue_id' => $issue->id]);
        $usedLine->units()->attach($unit->id);

        $undone = StockMovement::query()->create(['moved_at' => '2026-10-23 10:00:00', 'type' => 'used', 'storage_location_id' => $location->id, 'ticket_issue_id' => $issue->id, 'mistaken' => true]);
        StockMovementLine::query()->create(['stock_movement_id' => $undone->id, 'part_id' => $part->id, 'storage_location_id' => $location->id, 'quantity' => 10, 'direction' => -1, 'unit_cost' => 4.5, 'total_cost' => 45]);
    }

    public function test_costs_are_summed_from_live_payment_lines_by_type(): void
    {
        $this->seedMoney();
        IssueInspection::query()->create(['ticket_issue_id' => $this->issue->id, 'status' => 'done', 'estimated_cost' => 300]);
        IssueInspection::query()->create(['ticket_issue_id' => $this->issue->id, 'status' => 'done', 'estimated_cost' => 380]);
        IssueInspection::query()->create(['ticket_issue_id' => $this->issue->id, 'status' => 'done', 'estimated_cost' => 9999, 'mistaken' => true]);
        IssueApproval::query()->create(['ticket_issue_id' => $this->issue->id, 'amount' => 380, 'level' => 'manager', 'decision' => 'approved', 'approver_name' => 'Rita', 'decided_at' => now()]);

        $this->read()
            ->assertJsonPath('data.costs', [
                'estimated' => '380.00',
                'approved' => '380.00',
                'labor' => '250.00',
                'inspection' => '75.00',
            'inspection_fees' => '0.00',
                'parts' => '40.00',
                'gas' => '12.50',
                'other' => '5.00',
                'paid_total' => '382.50',
                'stock_used_value' => '13.50',
                'total_actual' => '396.00',
                'unpaid_purchases' => '8.25',
            ])
            ->assertJsonPath('data.workflow.approval_level_for.level', 'manager');
    }

    public function test_payments_purchases_and_stock_used_are_listed(): void
    {
        $this->seedMoney();

        $data = $this->read()->json('data');

        $this->assertCount(6, $data['payments']);
        $this->assertSame('2026-10-21', $data['payments'][0]['paid_at']);
        $this->assertSame(['type' => 'supplier', 'id' => Supplier::query()->value('id'), 'name' => 'Parts Town'], $data['payments'][0]['paid_to']);
        $this->assertFalse($data['payments'][0]['has_invoice']);
        $hourly = collect($data['payments'])->firstWhere('type', 'hourly');
        $this->assertSame('150.00', $hourly['amount']);
        $this->assertSame('INV-7', $hourly['invoice_number']);
        $this->assertTrue($hourly['has_invoice']);
        $this->assertSame('Sam Fixer', $hourly['paid_to']['name']);

        $this->assertCount(3, $data['purchases']);
        $fuse = collect($data['purchases'])->firstWhere('description', 'Fuse');
        $this->assertFalse($fuse['paid']);
        $this->assertSame('8.25', $fuse['total_cost']);
        $tape = collect($data['purchases'])->firstWhere('description', 'Tape');
        $this->assertTrue($tape['paid']);
        $this->assertSame(Payment::query()->where('supplier_id', '!=', null)->value('id'), $tape['payment_id']);

        $this->assertCount(1, $data['stock_used']);
        $this->assertSame('Relay R-12', $data['stock_used'][0]['part']['name']);
        $this->assertSame('13.50', $data['stock_used'][0]['value']);
        $this->assertSame('Main shelf', $data['stock_used'][0]['storage_location']['name']);
        $this->assertSame(['SN-'.$this->issue->id], $data['stock_used'][0]['serials']);
    }

    public function test_visits_come_from_the_trips_and_skip_mistaken_ones(): void
    {
        $trip = Trip::query()->create(['technician_id' => $this->technician->id, 'status' => 'open', 'planned_date' => '2026-10-24']);
        $stop = TripStop::query()->create(['trip_id' => $trip->id, 'store_id' => $this->store->id, 'status' => 'done', 'arrived_at' => '2026-10-24 09:00:00', 'left_at' => '2026-10-24 11:00:00']);
        TripStopIssue::query()->create(['trip_stop_id' => $stop->id, 'ticket_issue_id' => $this->issue->id, 'purpose' => 'work', 'task_status' => 'not_done', 'not_done_reason' => 'Needs a part']);

        $gone = Trip::query()->create(['technician_id' => $this->technician->id, 'status' => 'open', 'planned_date' => '2026-10-25', 'mistaken' => true]);
        $goneStop = TripStop::query()->create(['trip_id' => $gone->id, 'store_id' => $this->store->id]);
        TripStopIssue::query()->create(['trip_stop_id' => $goneStop->id, 'ticket_issue_id' => $this->issue->id]);

        $this->read()
            ->assertJsonCount(1, 'data.visits')
            ->assertJsonPath('data.visits.0.trip_id', $trip->id)
            ->assertJsonPath('data.visits.0.trip_stop_id', $stop->id)
            ->assertJsonPath('data.visits.0.technician.name', 'Sam Fixer')
            ->assertJsonPath('data.visits.0.store_number', '03795-00001')
            ->assertJsonPath('data.visits.0.task_status', 'not_done')
            ->assertJsonPath('data.visits.0.not_done_reason', 'Needs a part');
    }

    public function test_the_timeline_merges_everything_newest_first_without_private_notes(): void
    {
        Carbon::setTestNow('2026-10-20 08:00:00');
        $this->act('review')->assertOk();

        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->act('info-requests', ['question' => 'Which door?'])->assertOk();

        Carbon::setTestNow('2026-10-20 10:00:00');
        $request = IssueInfoRequest::query()->sole();
        $this->act("info-requests/{$request->id}/answer", ['answer' => 'Back door'])->assertOk();

        Carbon::setTestNow('2026-10-21 08:00:00');
        $this->act('schedule', ['technician_id' => $this->technician->id, 'date' => '2026-10-22'])->assertOk();

        Carbon::setTestNow('2026-10-21 09:00:00');
        $this->postJson($this->url('/notes'), ['body' => 'Bring a ladder'], $this->headers())->assertSuccessful();
        $this->issue->notes()->make(['body' => 'Secret'])->forceFill(['is_private' => true])->save();

        $timeline = $this->read()->json('data.timeline');
        $kinds = array_column($timeline, 'kind');

        $this->assertSame('note', $kinds[0]);
        $this->assertSame('Note: Bring a ladder', $timeline[0]['text']);
        $this->assertNotContains('Note: Secret', array_column($timeline, 'text'));
        $this->assertContains('info_requested', $kinds);
        $this->assertContains('info_answered', $kinds);
        $this->assertContains('scheduled', $kinds);
        $this->assertSame('New → In review', end($timeline)['text']);
        $this->assertSame('Dana Whitfield', end($timeline)['actor']);

        $times = array_map(fn (array $e) => Carbon::parse($e['at'])->getTimestamp(), $timeline);
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);

        // The booking and the status change it caused happened in the same
        // second: the status change (the result) comes first.
        $sameSecond = array_values(array_filter($timeline, fn (array $e) => Carbon::parse($e['at'])->equalTo('2026-10-21 08:00:00')));
        $this->assertSame(['status_changed', 'scheduled'], array_column($sameSecond, 'kind'));
    }

    public function test_the_core_fields_include_impact_and_contact_from_the_ticket_form(): void
    {
        $id = $this->postJson("/api/stores/{$this->store->store_number}/tickets", [
            'issues' => [[
                'other_title' => 'Fryer',
                'priority' => 'high',
                'description' => 'Not heating',
                'impact' => 'partly_down',
                'contact_name' => 'Mo',
                'contact_phone' => '614-555-0199',
            ]],
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.issues.0.status', 'new')
            ->assertJsonPath('data.issues.0.impact.value', 'partly_down')
            ->assertJsonPath('data.issues.0.contact_name', 'Mo')
            ->json('data.issues.0.id');

        $issue = TicketIssue::query()->findOrFail($id);

        $this->read($issue)
            ->assertJsonPath('data.title', 'Fryer')
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.status_label', 'New')
            ->assertJsonPath('data.step', 1)
            ->assertJsonPath('data.impact.label', 'Part of the store is down')
            ->assertJsonPath('data.contact_phone', '614-555-0199')
            ->assertJsonPath('data.creator.name', 'Dana Whitfield')
            ->assertJsonPath('data.workflow.next.0', ['action' => 'review', 'label' => 'Start the review', 'primary' => true])
            ->assertJsonPath('data.workflow.approval_level_for', null);
    }

    public function test_the_issue_list_does_not_query_per_issue(): void
    {
        $this->seedMoney();
        $extras = function (TicketIssue $issue) {
            IssueInfoRequest::query()->create(['ticket_issue_id' => $issue->id, 'question' => 'q']);
            IssueInspection::query()->create(['ticket_issue_id' => $issue->id, 'technician_id' => $this->technician->id, 'status' => 'done', 'estimated_cost' => 500]);
            IssueApproval::query()->create(['ticket_issue_id' => $issue->id, 'amount' => 500, 'level' => 'manager', 'decision' => 'approved', 'approver_name' => 'Rita', 'decided_at' => now()]);
            $this->act('schedule', ['technician_id' => $this->technician->id, 'date' => '2026-11-03'], $issue)->assertOk();
        };
        $extras($this->issue);
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/stores/{$this->store->store_number}/tickets/{$this->ticket->id}/issues", $this->headers())->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $one = $count();

        foreach (range(1, 4) as $i) {
            $issue = TicketIssue::factory()->for($this->ticket)->create(['status' => IssueStatus::InReview]);
            $this->seedMoney($issue);
            $extras($issue);
        }

        $this->assertSame($one, $count(), 'The issue list ran more queries with more issues.');
    }

    public function test_each_payment_lists_the_files_on_it_as_invoices(): void
    {
        $this->seedMoney();

        $payments = collect($this->read()->json('data.payments'));

        $hourly = $payments->firstWhere('type', 'hourly');
        $this->assertCount(1, $hourly['invoices']);
        $this->assertSame('inv7.pdf', $hourly['invoices'][0]['original_name']);
        $this->assertSame(['id', 'original_name', 'url'], array_keys($hourly['invoices'][0]));
        $this->assertStringEndsWith('attachments/inv7.pdf', $hourly['invoices'][0]['url']);

        $this->assertSame([], $payments->firstWhere('type', 'parts')['invoices']);
    }

    public function test_ticket_list_issue_rows_name_their_technicians_without_a_query_per_issue(): void
    {
        $this->issue->technicians()->attach($this->technician->id);

        $this->getJson("/api/stores/{$this->store->store_number}/tickets", $this->headers())->assertOk()
            ->assertJsonPath('data.0.issues.0.technicians', [['id' => $this->technician->id, 'name' => 'Sam Fixer']]);
        $this->getJson('/api/tickets', $this->headers())->assertOk()
            ->assertJsonPath('data.0.issues.0.technicians', [['id' => $this->technician->id, 'name' => 'Sam Fixer']]);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/stores/{$this->store->store_number}/tickets", $this->headers())->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $one = $count();

        foreach (range(1, 3) as $i) {
            $ticket = Ticket::factory()->for($this->store)->create();
            foreach (range(1, 2) as $j) {
                TicketIssue::factory()->for($ticket)->create()->technicians()->attach($this->technician->id);
            }
        }

        $this->assertSame($one, $count(), 'The ticket list ran more queries with more issues.');
    }

    public function test_the_ticket_list_stays_light(): void
    {
        $this->seedMoney();

        $this->getJson("/api/stores/{$this->store->store_number}/tickets", $this->headers())->assertOk()
            ->assertJsonPath('data.0.issues.0.status', 'new')
            ->assertJsonPath('data.0.issues.0.step', 1)
            ->assertJsonPath('data.0.issues.0.costs', null)
            ->assertJsonPath('data.0.issues.0.timeline', null);
    }
}
