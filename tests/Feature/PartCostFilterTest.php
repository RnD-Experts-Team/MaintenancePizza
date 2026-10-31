<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The two part-cost ticket filters in TicketService::applyFilters(), on
 * payments: what counts is the parts payment lines linked to the issues, on
 * payments not marked mistaken. One line can be linked to MANY issues, so a
 * naive SUM would double-count it.
 */
class PartCostFilterTest extends TestCase
{
    use RefreshDatabase;

    private function filter(array $query): array
    {
        $request = Request::create('/api/tickets', 'GET', $query);

        return app(TicketService::class)->index($request)
            ->getCollection()->pluck('id')->all();
    }

    /**
     * @param  array<int, TicketIssue>  $issues
     */
    private function partsLine(array $issues, string $amount, bool $mistaken = false, string $type = 'parts'): PaymentLine
    {
        $payment = Payment::query()->create([
            'paid_at' => '2026-10-01',
            'technician_id' => Technician::factory()->create()->id,
            'total' => $amount,
            'mistaken' => $mistaken,
        ]);

        $line = $payment->lines()->create([
            'type' => $type,
            'other_store' => 'Somewhere',
            'amount' => $amount,
            'part_id' => $type === 'parts' ? Part::factory()->create()->id : null,
            'quantity' => $type === 'parts' ? 1 : null,
            'unit_cost' => $type === 'parts' ? $amount : null,
        ]);
        $line->ticketIssues()->attach(array_map(fn (TicketIssue $i) => $i->id, $issues));

        return $line;
    }

    /**
     * A line shared across two issues of the same ticket must count ONCE
     * toward the whole-ticket total.
     */
    public function test_a_shared_line_counts_once_toward_the_ticket_total(): void
    {
        $ticket = Ticket::factory()->create();
        $issueA = TicketIssue::factory()->for($ticket)->create();
        $issueB = TicketIssue::factory()->for($ticket)->create();

        $this->partsLine([$issueA, $issueB], '100.00');

        $this->assertNotContains($ticket->id, $this->filter(['part_cost_total_gt' => 150]));
        $this->assertContains($ticket->id, $this->filter(['part_cost_total_gt' => 50]));
    }

    public function test_distinct_lines_on_one_ticket_are_summed(): void
    {
        $ticket = Ticket::factory()->create();
        $issue = TicketIssue::factory()->for($ticket)->create();

        $this->partsLine([$issue], '60.00');
        $this->partsLine([$issue], '60.00');

        $this->assertContains($ticket->id, $this->filter(['part_cost_total_gt' => 100]));
        $this->assertNotContains($ticket->id, $this->filter(['part_cost_total_gt' => 120]));
    }

    public function test_mistaken_payments_and_other_line_types_are_left_out(): void
    {
        $ticket = Ticket::factory()->create();
        $issue = TicketIssue::factory()->for($ticket)->create();

        $this->partsLine([$issue], '500.00', mistaken: true);
        $this->partsLine([$issue], '500.00', type: 'fixed');

        $this->assertNotContains($ticket->id, $this->filter(['part_cost_total_gt' => 10]));
        $this->assertNotContains($ticket->id, $this->filter(['part_cost_single_gt' => 10]));
    }

    /**
     * part_cost_single_gt is per-issue: it matches when at least ONE issue's
     * own parts spend exceeds the threshold, not the ticket's total.
     */
    public function test_single_issue_filter_is_scoped_to_one_issue(): void
    {
        $ticket = Ticket::factory()->create();
        $issueA = TicketIssue::factory()->for($ticket)->create();
        $issueB = TicketIssue::factory()->for($ticket)->create();

        $this->partsLine([$issueA], '60.00');
        $this->partsLine([$issueB], '60.00');

        $this->assertNotContains($ticket->id, $this->filter(['part_cost_single_gt' => 100]));
        $this->assertContains($ticket->id, $this->filter(['part_cost_total_gt' => 100]));
    }
}
