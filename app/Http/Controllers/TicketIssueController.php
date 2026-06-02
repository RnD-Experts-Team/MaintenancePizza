<?php

namespace App\Http\Controllers;

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
}
