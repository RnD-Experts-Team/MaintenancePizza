<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The ?q free-text filter on the ticket index.
 *
 * The coordinator hunts by store, by technician and by "the fryer thing".
 * The first two were already filterable; this covers the third.
 */
class TicketSearchFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<int> */
    private function filter(array $query): array
    {
        $request = Request::create('/api/tickets', 'GET', $query);

        return app(TicketService::class)->index($request)
            ->getCollection()->pluck('id')->all();
    }

    /**
     * A digit string matches the ticket id exactly AND any store number
     * containing those digits -- both readings are wanted, since a coordinator
     * types "412" for a ticket and "3795" for a store. The decoy here therefore
     * gets a digit-free store number, so the assertion is about the id branch
     * rather than about whichever digits faker happened to produce.
     */
    public function test_it_matches_a_ticket_by_its_exact_id(): void
    {
        $wanted = Ticket::factory()->create();
        $other  = Ticket::factory()
            ->for(Store::factory()->create(['store_number' => 'ALPHA-DEPOT']))
            ->create();

        $found = $this->filter(['q' => (string) $wanted->id]);

        $this->assertContains($wanted->id, $found);
        $this->assertNotContains($other->id, $found);
    }

    public function test_it_matches_by_store_number(): void
    {
        $store  = Store::factory()->create(['store_number' => '03795-00001']);
        $wanted = Ticket::factory()->for($store)->create();
        $other  = Ticket::factory()
            ->for(Store::factory()->create(['store_number' => 'ALPHA-DEPOT']))
            ->create();

        $found = $this->filter(['q' => '3795-000']);

        $this->assertContains($wanted->id, $found);
        $this->assertNotContains($other->id, $found);
    }

    /**
     * A ticket with store_id = null cannot match through the store relation,
     * so without the other_store clause these would be invisible to search.
     */
    public function test_it_matches_an_other_store_ticket_by_its_free_text_location(): void
    {
        $wanted = Ticket::factory()->otherStore('Riverside Depot')->create();
        $other  = Ticket::factory()
            ->for(Store::factory()->create(['store_number' => 'ALPHA-DEPOT']))
            ->create();

        $found = $this->filter(['q' => 'riverside']);

        $this->assertContains($wanted->id, $found);
        $this->assertNotContains($other->id, $found);
    }

    public function test_it_matches_a_free_text_issue_title(): void
    {
        $wanted = Ticket::factory()->create();
        TicketIssue::factory()->for($wanted)->create(['other_title' => 'Fryer not heating']);

        $other = Ticket::factory()->create();
        TicketIssue::factory()->for($other)->create(['other_title' => 'Door handle loose']);

        $found = $this->filter(['q' => 'fryer']);

        $this->assertContains($wanted->id, $found);
        $this->assertNotContains($other->id, $found);
    }

    public function test_it_matches_a_catalog_issue_title(): void
    {
        $catalog = Issue::factory()->create(['title' => 'Walk-in freezer warm']);

        $wanted = Ticket::factory()->create();
        TicketIssue::factory()->for($wanted)->create([
            'issue_id' => $catalog->id,
            'other_title' => null,
        ]);

        $other = Ticket::factory()->create();
        TicketIssue::factory()->for($other)->create(['other_title' => 'Something else entirely']);

        $found = $this->filter(['q' => 'freezer']);

        $this->assertContains($wanted->id, $found);
        $this->assertNotContains($other->id, $found);
    }

    public function test_it_matches_an_issue_description(): void
    {
        $wanted = Ticket::factory()->create();
        TicketIssue::factory()->for($wanted)->create([
            'other_title' => 'Unit fault',
            'description' => 'The compressor rattles when it cycles.',
        ]);

        $other = Ticket::factory()->create();
        TicketIssue::factory()->for($other)->create([
            'other_title' => 'Unit fault',
            'description' => 'Nothing to do with that.',
        ]);

        $found = $this->filter(['q' => 'compressor']);

        $this->assertContains($wanted->id, $found);
        $this->assertNotContains($other->id, $found);
    }

    /**
     * The regression guard. Every other clause in applyFilters() is a
     * top-level where/whereHas, so an ungrouped orWhere would bind as
     * "(everything else) OR q" and return tickets that fail every other
     * filter. If this test ever goes red, the grouping closure was unwrapped.
     */
    public function test_q_narrows_the_other_filters_rather_than_widening_them(): void
    {
        // Matches q, but its issue is complete.
        $completed = Ticket::factory()->create();
        TicketIssue::factory()->for($completed)->create([
            'other_title' => 'Fryer not heating',
            'status' => IssueStatus::Complete->value,
        ]);

        // Matches q AND is still pending.
        $pending = Ticket::factory()->create();
        TicketIssue::factory()->for($pending)->create([
            'other_title' => 'Fryer thermostat drifting',
            'status' => IssueStatus::Pending->value,
        ]);

        $found = $this->filter(['q' => 'fryer', 'issue_statuses' => ['pending']]);

        $this->assertContains($pending->id, $found);
        $this->assertNotContains($completed->id, $found);
    }

    public function test_a_blank_or_whitespace_q_is_a_no_op(): void
    {
        $a = Ticket::factory()->create();
        $b = Ticket::factory()->create();

        foreach (['', '   '] as $blank) {
            $found = $this->filter(['q' => $blank]);
            $this->assertContains($a->id, $found);
            $this->assertContains($b->id, $found);
        }
    }

    public function test_matching_is_case_insensitive(): void
    {
        $wanted = Ticket::factory()->create();
        TicketIssue::factory()->for($wanted)->create(['other_title' => 'Fryer not heating']);

        $this->assertContains($wanted->id, $this->filter(['q' => 'FRYER']));
    }
}
