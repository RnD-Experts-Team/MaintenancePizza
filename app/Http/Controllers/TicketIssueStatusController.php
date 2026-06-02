<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetIssueStatusRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\TicketIssueService;

class TicketIssueStatusController extends Controller
{
    public function __construct(private TicketIssueService $issues) {}

    /**
     * Set the status of one or many issues at once.
     */
    public function store(SetIssueStatusRequest $request, Store $store, Ticket $ticket)
    {
        $data = $request->validated();

        return ['data' => $this->issues->changeStatuses($ticket, $data['ticket_issue_ids'], $data['status'])];
    }
}
