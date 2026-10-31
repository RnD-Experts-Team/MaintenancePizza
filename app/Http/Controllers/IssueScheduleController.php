<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueScheduleMoveRequest;
use App\Http\Requests\IssueScheduleStoreRequest;
use App\Models\Assignment;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\AssignmentService;
use App\Services\TicketIssueService;

/**
 * Scheduling the work and moving a booking (docs/MAINTENANCE.md §2).
 */
class IssueScheduleController extends Controller
{
    public function __construct(
        private AssignmentService $assignments,
        private TicketIssueService $issues,
    ) {
    }

    public function store(IssueScheduleStoreRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): array
    {
        $booked = $this->assignments->scheduleWork($ticketIssue, $request->validated(), $request->user());

        return $this->issues->respond($ticketIssue, $booked['token'], $booked['trip']);
    }

    public function move(IssueScheduleMoveRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue, Assignment $assignment): array
    {
        $moved = $this->assignments->move($ticketIssue, $assignment, $request->validated(), $request->user());

        return $this->issues->respond($ticketIssue, $moved['token'], $moved['trip']);
    }
}
