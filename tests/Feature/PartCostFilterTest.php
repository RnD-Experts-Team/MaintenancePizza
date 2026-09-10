<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartUsage;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Characterization tests for the two part-cost ticket filters in
 * TicketService::applyFilters(). One PartUsage can attach to MANY ticket
 * issues, so a naive SUM double-counts a shared part. These tests pin the
 * existing behaviour down before the part-usage schema grows quantity /
 * unit_cost / returns, so that `cost` keeping its "gross total outlay"
 * meaning is a verified fact rather than an assertion.
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
     * A part shared across two issues of the same ticket must count ONCE
     * toward the whole-ticket total. This is what the DISTINCT pu.id
     * subquery in part_cost_total_gt exists for.
     */
    public function test_shared_part_usage_counts_once_toward_the_ticket_total(): void
    {
        $ticket = Ticket::factory()->create();
        $issueA = TicketIssue::factory()->for($ticket)->create();
        $issueB = TicketIssue::factory()->for($ticket)->create();

        $usage = PartUsage::factory()->create(['part_id' => Part::factory(), 'cost' => 100.00]);
        $usage->ticketIssues()->attach([$issueA->id, $issueB->id]);

        // Counted once => 100, so a > 150 threshold must exclude the ticket.
        $this->assertNotContains($ticket->id, $this->filter(['part_cost_total_gt' => 150]));

        // ...and a > 50 threshold must include it.
        $this->assertContains($ticket->id, $this->filter(['part_cost_total_gt' => 50]));
    }

    public function test_distinct_part_usages_on_one_ticket_are_summed(): void
    {
        $ticket = Ticket::factory()->create();
        $issue = TicketIssue::factory()->for($ticket)->create();

        foreach ([60.00, 60.00] as $cost) {
            $usage = PartUsage::factory()->create(['part_id' => Part::factory(), 'cost' => $cost]);
            $usage->ticketIssues()->attach($issue->id);
        }

        $this->assertContains($ticket->id, $this->filter(['part_cost_total_gt' => 100]));
        $this->assertNotContains($ticket->id, $this->filter(['part_cost_total_gt' => 120]));
    }

    public function test_mistaken_part_usages_are_excluded_from_both_filters(): void
    {
        $ticket = Ticket::factory()->create();
        $issue = TicketIssue::factory()->for($ticket)->create();

        $usage = PartUsage::factory()->mistaken()->create(['part_id' => Part::factory(), 'cost' => 500.00]);
        $usage->ticketIssues()->attach($issue->id);

        $this->assertNotContains($ticket->id, $this->filter(['part_cost_total_gt' => 10]));
        $this->assertNotContains($ticket->id, $this->filter(['part_cost_single_gt' => 10]));
    }

    /**
     * part_cost_single_gt is per-issue: it matches when at least ONE issue's
     * own summed cost exceeds the threshold, not the ticket's total.
     */
    public function test_single_issue_filter_is_scoped_to_one_issue(): void
    {
        $ticket = Ticket::factory()->create();
        $issueA = TicketIssue::factory()->for($ticket)->create();
        $issueB = TicketIssue::factory()->for($ticket)->create();

        foreach ([$issueA, $issueB] as $issue) {
            $usage = PartUsage::factory()->create(['part_id' => Part::factory(), 'cost' => 60.00]);
            $usage->ticketIssues()->attach($issue->id);
        }

        // Ticket total is 120, but no single issue exceeds 100.
        $this->assertNotContains($ticket->id, $this->filter(['part_cost_single_gt' => 100]));
        $this->assertContains($ticket->id, $this->filter(['part_cost_total_gt' => 100]));
    }
}
