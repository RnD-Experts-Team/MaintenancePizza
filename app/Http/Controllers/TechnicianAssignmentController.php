<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachTechniciansRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\TicketIssueService;

class TechnicianAssignmentController extends Controller
{
    public function __construct(private TicketIssueService $issues) {}

    /**
     * Attach technicians to one or more issues (without scheduling a date).
     */
    public function store(AttachTechniciansRequest $request, Store $store, Ticket $ticket)
    {
        $data = $request->validated();

        return ['data' => $this->issues->attachTechnicians($ticket, $data['ticket_issue_ids'], $data['technician_ids'])];
    }
}
