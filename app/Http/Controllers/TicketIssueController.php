<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignPriorityRequest;
use App\Http\Requests\UpdateTicketIssueRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\TicketIssueService;

class TicketIssueController extends Controller
{
    public function __construct(private TicketIssueService $issues) {}

    /**
     * List every issue of a ticket with its full workflow history.
     */
    public function index(Store $store, Ticket $ticket)
    {
        return ['data' => $this->issues->index($ticket)];
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
