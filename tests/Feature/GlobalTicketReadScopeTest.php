<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * GET /api/tickets/{ticket}/issues?store_id=X
 *
 * pizzasys checks the caller against store X, but nothing used to tie the
 * ticket to X -- so access to one store read any store's ticket by id. The
 * ticket must now be the store the query names.
 */
class GlobalTicketReadScopeTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    public function test_a_ticket_from_another_store_is_not_found(): void
    {
        $this->fakeAuthServer();
        [$mine, $theirs] = Store::factory()->count(2)->create();
        $ticket = Ticket::factory()->for($theirs)->create();
        TicketIssue::factory()->for($ticket)->create();

        $this->getJson("/api/tickets/{$ticket->id}/issues?store_id={$mine->store_number}", $this->headers())
            ->assertNotFound();
    }

    public function test_a_ticket_from_the_named_store_is_readable(): void
    {
        $this->fakeAuthServer();
        $store = Store::factory()->create();
        $ticket = Ticket::factory()->for($store)->create();
        TicketIssue::factory()->for($ticket)->create();

        $this->getJson("/api/tickets/{$ticket->id}/issues?store_id={$store->store_number}", $this->headers())
            ->assertOk()
            ->assertJsonPath('ticket.id', $ticket->id);
    }

    public function test_without_a_store_the_rule_alone_decides(): void
    {
        $this->fakeAuthServer();
        $ticket = Ticket::factory()->create();
        TicketIssue::factory()->for($ticket)->create();

        $this->getJson("/api/tickets/{$ticket->id}/issues", $this->headers())->assertOk();
    }

    public function test_an_off_system_ticket_stays_readable(): void
    {
        $this->fakeAuthServer();
        $store = Store::factory()->create();
        $ticket = Ticket::factory()->otherStore('Warehouse')->create();
        TicketIssue::factory()->for($ticket)->create();

        $this->getJson("/api/tickets/{$ticket->id}/issues?store_id={$store->store_number}", $this->headers())->assertOk();
    }
}
