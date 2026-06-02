<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeferIssueRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\TicketIssueService;

class TicketIssueDeferralController extends Controller
{
    public function __construct(private TicketIssueService $issues) {}

    /**
     * Defer an issue (records the reason) and spawn a pending child.
     */
    public function __invoke(DeferIssueRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue)
    {
        return ['data' => $this->issues->defer($ticket, $ticketIssue, $request->validated()['reason'])];
    }
}
