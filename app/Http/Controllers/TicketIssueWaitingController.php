<?php

namespace App\Http\Controllers;

use App\Http\Requests\WaitIssueRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\TicketIssueService;

class TicketIssueWaitingController extends Controller
{
    public function __construct(private TicketIssueService $issues) {}

    /**
     * Mark an issue as Waiting (records the reason). Unlike deferral it spawns no child.
     */
    public function __invoke(WaitIssueRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue)
    {
        return ['data' => $this->issues->wait($ticketIssue, $request->validated()['reason'])];
    }
}
