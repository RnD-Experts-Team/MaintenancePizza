<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelIssueRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\TicketIssueService;

class TicketIssueCancellationController extends Controller
{
    public function __construct(private TicketIssueService $issues) {}

    /**
     * Cancel an issue (records the reason). Unlike deferral it spawns no child.
     */
    public function __invoke(CancelIssueRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue)
    {
        return ['data' => $this->issues->cancel($ticketIssue, $request->validated()['reason'])];
    }
}
