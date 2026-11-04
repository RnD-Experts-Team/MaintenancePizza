<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\Warranty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * /stores/{store}/tickets/{ticket}/warranties/{warranty}/... is not
 * scope-bound, and pizzasys only authorises the STORE in the URL. Without the
 * check in the leaf requests (ChecksTicketRecord), a user cleared for their own
 * store could act on any other store's records by putting their store in front
 * of someone else's ids.
 */
class LeafRouteOwnershipTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $mine;

    private Ticket $myTicket;

    private Ticket $theirTicket;

    private Warranty $theirWarranty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();

        $this->mine = Store::factory()->create();
        $theirs = Store::factory()->create();
        $this->myTicket = Ticket::factory()->for($this->mine)->create();
        TicketIssue::factory()->for($this->myTicket)->create();
        $this->theirTicket = Ticket::factory()->for($theirs)->create();
        $theirIssue = TicketIssue::factory()->for($this->theirTicket)->create();

        $this->theirWarranty = Warranty::factory()->create();
        $this->theirWarranty->ticketIssues()->attach($theirIssue->id);
    }

    public function test_another_stores_ticket_cannot_be_reached_through_my_store(): void
    {
        $this->postJson(
            "/api/stores/{$this->mine->store_number}/tickets/{$this->theirTicket->id}/warranties/{$this->theirWarranty->id}/notes",
            ['body' => 'sneaky'],
            $this->headers(),
        )->assertNotFound();

        $this->assertSame(0, $this->theirWarranty->notes()->count());
    }

    public function test_a_record_from_another_ticket_cannot_be_reached_through_my_ticket(): void
    {
        $this->postJson(
            "/api/stores/{$this->mine->store_number}/tickets/{$this->myTicket->id}/warranties/{$this->theirWarranty->id}/notes",
            ['body' => 'sneaky'],
            $this->headers(),
        )->assertNotFound();

        $this->assertSame(0, $this->theirWarranty->notes()->count());
    }

    public function test_my_own_record_on_my_own_ticket_goes_through(): void
    {
        $warranty = Warranty::factory()->create();
        $warranty->ticketIssues()->attach($this->myTicket->ticketIssues()->first()->id);

        $this->postJson(
            "/api/stores/{$this->mine->store_number}/tickets/{$this->myTicket->id}/warranties/{$warranty->id}/notes",
            ['body' => 'Five years on the compressor'],
            $this->headers(),
        )->assertCreated();
    }

    public function test_a_booking_spanning_two_of_my_tickets_is_reachable_through_either(): void
    {
        $second = Ticket::factory()->for($this->mine)->create();
        $issueA = $this->myTicket->ticketIssues()->first();
        $issueB = TicketIssue::factory()->for($second)->create();

        $assignment = Assignment::factory()->create();
        $assignment->ticketIssues()->attach([$issueA->id, $issueB->id]);

        foreach ([$this->myTicket, $second] as $ticket) {
            $this->postJson(
                "/api/stores/{$this->mine->store_number}/tickets/{$ticket->id}/assignments/{$assignment->id}/notes",
                ['body' => 'on the way'],
                $this->headers(),
            )->assertCreated();
        }
    }
}
