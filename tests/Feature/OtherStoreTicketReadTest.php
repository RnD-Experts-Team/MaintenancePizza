<?php

namespace Tests\Feature;

use App\Http\Controllers\TicketIssueController;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A ticket created through POST /tickets carries other_store and a null
 * store_id. Every issue-reading route lived under /stores/{store}/..., whose
 * scopeBindings() has no store to bind -- so those tickets could be created
 * and then never read back.
 *
 * These tests pin the unscoped route that closes that hole.
 */
class OtherStoreTicketReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_unscoped_issues_route_is_registered(): void
    {
        $route = Route::getRoutes()->getByName('tickets.issues.global');

        $this->assertNotNull($route, 'GET /tickets/{ticket}/issues is not registered.');
        $this->assertSame('api/tickets/{ticket}/issues', $route->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertSame(
            TicketIssueController::class . '@globalIndex',
            $route->getActionName()
        );
    }

    /**
     * The literal analytics segment must still win over the {ticket} wildcard.
     * If the wildcard route were declared first, /tickets/analytics would try
     * to bind a ticket called "analytics" and 404.
     */
    public function test_the_analytics_route_still_wins_over_the_ticket_wildcard(): void
    {
        $this->assertSame(
            'tickets.analytics',
            Route::getRoutes()->match(
                \Illuminate\Http\Request::create('/api/tickets/analytics', 'GET')
            )->getName()
        );
    }

    public function test_an_other_store_ticket_can_have_its_issues_read(): void
    {
        $ticket = Ticket::factory()->otherStore('Riverside Depot')->create();
        $issue  = TicketIssue::factory()->for($ticket)->create(['other_title' => 'Fryer not heating']);

        $this->assertNull($ticket->store_id, 'Precondition: an other_store ticket has no store.');

        $result = app(TicketIssueController::class)->globalIndex($ticket);

        $this->assertCount(1, $result['data']);
        $this->assertSame($issue->id, $result['data'][0]['id']);

        // The ticket rides along: with no store segment in the URL, a caller
        // reading this standalone has no other way to learn where it is.
        $this->assertSame($ticket->id, $result['ticket']['id']);
        $this->assertNull($result['ticket']['store_id']);
        $this->assertSame('Riverside Depot', $result['ticket']['other_store']);
    }

    /**
     * The unscoped route must return exactly what the store-scoped twin does,
     * or the frontend would need two shapes for one screen.
     */
    public function test_it_returns_the_same_payload_as_the_store_scoped_route(): void
    {
        $ticket = Ticket::factory()->create();
        TicketIssue::factory()->for($ticket)->create();

        $controller = app(TicketIssueController::class);

        // Same issues, plus the ticket the scoped route did not need to send.
        $this->assertEquals(
            $controller->index($ticket->store, $ticket)['data'],
            $controller->globalIndex($ticket)['data']
        );
        $this->assertSame(
            $ticket->store->store_number,
            $controller->globalIndex($ticket)['ticket']['store']['store_number']
        );
    }
}
