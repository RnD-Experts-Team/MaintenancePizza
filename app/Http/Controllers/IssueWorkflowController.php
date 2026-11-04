<?php

namespace App\Http\Controllers;

use App\Enums\IssueStatus;
use App\Http\Requests\IssueCloseRequest;
use App\Http\Requests\IssueConfirmationRequest;
use App\Http\Requests\IssueJumpRequest;
use App\Http\Requests\IssueReviewRequest;
use App\Http\Requests\IssueWorkDoneRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\IssueWorkflowService;
use App\Services\TicketIssueService;

/**
 * The issue workflow steps that act on the issue itself
 * (docs/MAINTENANCE.md §2): review, work done, the store's confirmation,
 * close and jump. Each answers with the issue's full read; a step that does
 * not fit the current status is a 422 with errors.status saying why.
 */
class IssueWorkflowController extends Controller
{
    public function __construct(
        private IssueWorkflowService $workflow,
        private TicketIssueService $issues,
    ) {
    }

    public function review(IssueReviewRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): array
    {
        $this->workflow->review($ticketIssue, $request->validated());

        return $this->issues->respond($ticketIssue);
    }

    public function workDone(IssueWorkDoneRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): array
    {
        $this->workflow->workDone($ticketIssue, $request->validated(), (array) $request->file('files', []), $request->user());

        return $this->issues->respond($ticketIssue);
    }

    public function confirmation(IssueConfirmationRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): array
    {
        $this->workflow->confirm(
            $ticketIssue,
            $request->boolean('working'),
            $request->validated('note'),
            $request->validated('confirmed_by_name'),
            $request->user(),
        );

        return $this->issues->respond($ticketIssue);
    }

    public function close(IssueCloseRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): array
    {
        $this->workflow->close($ticketIssue, $request->validated());

        return $this->issues->respond($ticketIssue);
    }

    public function jump(IssueJumpRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): array
    {
        $this->workflow->jump($ticketIssue, IssueStatus::from($request->validated('status')), $request->validated('reason'));

        return $this->issues->respond($ticketIssue);
    }
}
