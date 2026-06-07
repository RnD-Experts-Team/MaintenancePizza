<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelIssueRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\TicketIssueService;
use App\Services\TicketService;

class TicketCancellationController extends Controller
{
    public function __construct(
        private TicketIssueService $issues,
        private TicketService $tickets,
    ) {}

    public function __invoke(CancelIssueRequest $request, Store $store, Ticket $ticket)
    {
        $this->issues->cancelAll($ticket, $request->validated()['reason']);

        return ['data' => $this->tickets->presentFresh($ticket)];
    }
}
