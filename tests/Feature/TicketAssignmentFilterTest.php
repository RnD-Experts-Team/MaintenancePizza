<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\Assignment;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\TicketAnalyticsService;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ?assigned_from / ?assigned_to on the ticket index, and the overdue/stuck
 * counts that ride along on ?include_analytics=1.
 *
 * Without the date filter there is no way to ask "what is scheduled today" --
 * created_at cannot answer it, because a ticket raised in March is routinely
 * worked in September.
 */
class TicketAssignmentFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<int> */
    private function filter(array $query): array
    {
        $request = Request::create('/api/tickets', 'GET', $query);

        return app(TicketService::class)->index($request)
            ->getCollection()->pluck('id')->all();
    }

    /** @return array<string, mixed> */
    private function attention(array $query = []): array
    {
        $request = Request::create('/api/tickets/analytics', 'GET', $query);

        return app(TicketAnalyticsService::class)->summarize($request)['attention'];
    }

    private function scheduleIssue(TicketIssue $issue, string $date, bool $mistaken = false): Assignment
    {
        $assignment = Assignment::factory()->create([
            'assigned_date' => $date,
            'mistaken' => $mistaken,
        ]);
        $assignment->ticketIssues()->attach($issue->id);

        return $assignment;
    }

    public function test_it_bounds_tickets_by_their_assignment_window(): void
    {
        $inside = Ticket::factory()->create();
        $this->scheduleIssue(TicketIssue::factory()->for($inside)->create(), '2026-01-15');

        $before = Ticket::factory()->create();
        $this->scheduleIssue(TicketIssue::factory()->for($before)->create(), '2025-12-31');

        $after = Ticket::factory()->create();
        $this->scheduleIssue(TicketIssue::factory()->for($after)->create(), '2026-02-01');

        $found = $this->filter(['assigned_from' => '2026-01-01', 'assigned_to' => '2026-01-31']);

        $this->assertContains($inside->id, $found);
        $this->assertNotContains($before->id, $found);
        $this->assertNotContains($after->id, $found);
    }

    public function test_either_bound_works_on_its_own(): void
    {
        $early = Ticket::factory()->create();
        $this->scheduleIssue(TicketIssue::factory()->for($early)->create(), '2026-01-05');

        $late = Ticket::factory()->create();
        $this->scheduleIssue(TicketIssue::factory()->for($late)->create(), '2026-03-05');

        $fromOnly = $this->filter(['assigned_from' => '2026-02-01']);
        $this->assertContains($late->id, $fromOnly);
        $this->assertNotContains($early->id, $fromOnly);

        $toOnly = $this->filter(['assigned_to' => '2026-02-01']);
        $this->assertContains($early->id, $toOnly);
        $this->assertNotContains($late->id, $toOnly);
    }

    /**
     * Mistaken assignments never count, exactly as mistaken part usages never
     * count in part_cost_* and mistaken payables never count in payment_statuses.
     */
    public function test_a_mistaken_assignment_does_not_match(): void
    {
        $ticket = Ticket::factory()->create();
        $this->scheduleIssue(TicketIssue::factory()->for($ticket)->create(), '2026-01-15', mistaken: true);

        $this->assertNotContains(
            $ticket->id,
            $this->filter(['assigned_from' => '2026-01-01', 'assigned_to' => '2026-01-31'])
        );
    }

    /**
     * Pins the composition decision: two independent whereHas clauses mean
     * "has AN issue in the window AND has AN issue worked by this technician",
     * possibly two different issues. That is how priorities, issue_statuses and
     * issue_ids already compose, so it is the consistent reading -- but it is a
     * decision, so it gets a test rather than a comment alone.
     */
    public function test_assignment_dates_and_technician_ids_compose_across_different_issues(): void
    {
        $technician = Technician::factory()->create();

        $ticket = Ticket::factory()->create();
        $scheduled = TicketIssue::factory()->for($ticket)->create();
        $worked    = TicketIssue::factory()->for($ticket)->create();

        $this->scheduleIssue($scheduled, '2026-01-15');
        $worked->technicians()->attach($technician->id);

        $found = $this->filter([
            'assigned_from'  => '2026-01-01',
            'assigned_to'    => '2026-01-31',
            'technician_ids' => [$technician->id],
        ]);

        $this->assertContains($ticket->id, $found);
    }

    public function test_overdue_counts_only_non_terminal_issues_scheduled_in_the_past(): void
    {
        $yesterday = Carbon::yesterday()->toDateString();
        $tomorrow  = Carbon::tomorrow()->toDateString();

        $ticket = Ticket::factory()->create();

        $late = TicketIssue::factory()->for($ticket)->create(['status' => IssueStatus::Assigned->value]);
        $this->scheduleIssue($late, $yesterday);

        $done = TicketIssue::factory()->for($ticket)->create(['status' => IssueStatus::Complete->value]);
        $this->scheduleIssue($done, $yesterday);

        $upcoming = TicketIssue::factory()->for($ticket)->create(['status' => IssueStatus::Assigned->value]);
        $this->scheduleIssue($upcoming, $tomorrow);

        $this->assertSame(1, $this->attention()['overdue']);
    }

    /**
     * "Latest assignment" is MAX(assignments.id), not MAX(assigned_date).
     * A second assignment created later, for an EARLIER date, supersedes the
     * first -- so this issue IS overdue even though a later date exists on it.
     */
    public function test_the_latest_assignment_is_by_creation_order_not_by_date(): void
    {
        $ticket = Ticket::factory()->create();
        $issue = TicketIssue::factory()->for($ticket)->create(['status' => IssueStatus::Assigned->value]);

        $this->scheduleIssue($issue, Carbon::tomorrow()->toDateString());
        $this->scheduleIssue($issue, Carbon::yesterday()->toDateString());

        $this->assertSame(1, $this->attention()['overdue']);
    }

    public function test_a_mistaken_assignment_never_makes_an_issue_overdue(): void
    {
        $ticket = Ticket::factory()->create();
        $issue = TicketIssue::factory()->for($ticket)->create(['status' => IssueStatus::Assigned->value]);
        $this->scheduleIssue($issue, Carbon::yesterday()->toDateString(), mistaken: true);

        $this->assertSame(0, $this->attention()['overdue']);
    }

    public function test_an_issue_that_was_never_assigned_is_not_overdue(): void
    {
        $ticket = Ticket::factory()->create();
        TicketIssue::factory()->for($ticket)->create(['status' => IssueStatus::Pending->value]);

        $this->assertSame(0, $this->attention()['overdue']);
    }

    public function test_stuck_counts_waiting_issues_and_agrees_with_the_status_breakdown(): void
    {
        $ticket = Ticket::factory()->create();
        TicketIssue::factory()->for($ticket)->create(['status' => IssueStatus::Waiting->value]);
        TicketIssue::factory()->for($ticket)->create(['status' => IssueStatus::Waiting->value]);
        TicketIssue::factory()->for($ticket)->create(['status' => IssueStatus::Pending->value]);

        $request = Request::create('/api/tickets/analytics', 'GET', []);
        $summary = app(TicketAnalyticsService::class)->summarize($request);

        $fromBreakdown = collect($summary['issues']['status_breakdown'])
            ->firstWhere('status', IssueStatus::Waiting->value)['count'];

        $this->assertSame(2, $summary['attention']['stuck']);
        $this->assertSame($fromBreakdown, $summary['attention']['stuck']);
    }

    /**
     * "In the past" is relative to the server's date, so the frontend must be
     * told which day the count was computed for.
     */
    public function test_attention_reports_the_date_it_was_computed_for(): void
    {
        $this->assertSame(Carbon::today()->toDateString(), $this->attention()['as_of']);
    }

    /**
     * The counts must narrow with the active filters, like every other analytic.
     */
    public function test_overdue_respects_the_active_filters(): void
    {
        $yesterday = Carbon::yesterday()->toDateString();

        $wanted = Ticket::factory()->create();
        $a = TicketIssue::factory()->for($wanted)->create([
            'status' => IssueStatus::Assigned->value,
            'other_title' => 'Fryer not heating',
        ]);
        $this->scheduleIssue($a, $yesterday);

        $other = Ticket::factory()->create();
        $b = TicketIssue::factory()->for($other)->create([
            'status' => IssueStatus::Assigned->value,
            'other_title' => 'Door handle loose',
        ]);
        $this->scheduleIssue($b, $yesterday);

        $this->assertSame(2, $this->attention()['overdue']);
        $this->assertSame(1, $this->attention(['q' => 'fryer'])['overdue']);
    }
}
