<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignPriorityRequest;
use App\Http\Requests\UpdateTicketIssueRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\TicketIssueService;
use App\Services\TicketService;

class TicketIssueController extends Controller
{
    public function __construct(
        private TicketIssueService $issues,
        private TicketService $tickets,
    ) {}

    /**
     * List every issue of a ticket with its full workflow history.
     */
    public function index(Store $store, Ticket $ticket)
    {
        return ['data' => $this->issues->index($ticket)];
    }

    /**
     * The same listing, reached without a {store} segment.
     *
     * Not a convenience: a ticket created through POST /tickets carries
     * other_store and a null store_id, so it can never bind inside the
     * /stores/{store}/... group. Without this route those tickets can be
     * created and then never read back.
     */
    public function globalIndex(Ticket $ticket)
    {
        // Ships the ticket alongside its issues, which the store-scoped twin
        // does not need to: that route already told the caller which store it
        // was, in the URL. Here there is no store segment by design, and a
        // caller reading a ticket standalone still has to know where it is and
        // -- for every write that follows -- its store_number, since that is
        // the route key the rest of the API binds on.
        $ticket->loadMissing('store', 'creator');

        return [
            'data' => $this->issues->index($ticket),
            'ticket' => $this->tickets->present($ticket),
        ];
    }

    /**
     * A single issue with its full workflow history.
     */
    public function show(Store $store, Ticket $ticket, TicketIssue $ticketIssue)
    {
        return ['data' => $this->issues->show($ticketIssue)];
    }

    /**
     * Re-link an issue to a different catalog issue.
     */
    public function update(UpdateTicketIssueRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue)
    {
        return ['data' => $this->issues->update($ticketIssue, $request->validated()['issue_id'])];
    }

    /**
     * Set (or clear, with a null priority) the staff-assigned priority for an
     * issue. Distinct from and never overwrites the priority chosen at
     * ticket creation.
     */
    public function assignPriority(AssignPriorityRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue)
    {
        return ['data' => $this->issues->assignPriority($ticketIssue, $request->validated()['priority'] ?? null)];
    }
}
