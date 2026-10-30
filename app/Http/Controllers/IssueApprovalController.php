<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueApprovalAskRequest;
use App\Http\Requests\IssueApprovalStoreRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\ApprovalService;
use App\Services\TicketIssueService;

/**
 * Asking for approval of a quote and recording the decision
 * (docs/MAINTENANCE-V2.md §2).
 */
class IssueApprovalController extends Controller
{
    public function __construct(
        private ApprovalService $approvals,
        private TicketIssueService $issues,
    ) {
    }

    public function ask(IssueApprovalAskRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): array
    {
        $this->approvals->request($ticketIssue, $request->validated('amount'), $request->validated('inspection_id'));

        return $this->issues->respond($ticketIssue);
    }

    public function store(IssueApprovalStoreRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): array
    {
        $this->approvals->record($ticketIssue, $request->validated(), (array) $request->file('files', []), $request->user());

        return $this->issues->respond($ticketIssue);
    }
}
