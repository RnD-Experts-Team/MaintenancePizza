<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueInspectionResultRequest;
use App\Http\Requests\IssueInspectionStoreRequest;
use App\Models\IssueInspection;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\InspectionService;
use App\Services\TicketIssueService;

/**
 * Booking an inspection and recording what it found (docs/MAINTENANCE-V2.md §2).
 */
class IssueInspectionController extends Controller
{
    public function __construct(
        private InspectionService $inspections,
        private TicketIssueService $issues,
    ) {
    }

    public function store(IssueInspectionStoreRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): array
    {
        $booked = $this->inspections->book($ticketIssue, $request->validated(), $request->user());

        return $this->issues->respond($ticketIssue, $booked['token'], $booked['trip']);
    }

    public function result(IssueInspectionResultRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue, IssueInspection $inspection): array
    {
        $this->inspections->recordResult($ticketIssue, $inspection, $request->validated(), (array) $request->file('files', []));

        return $this->issues->respond($ticketIssue);
    }
}
