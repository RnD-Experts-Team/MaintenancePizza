<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueInfoAnswerRequest;
use App\Http\Requests\IssueInfoRequestStoreRequest;
use App\Models\IssueInfoRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\InfoRequestService;
use App\Services\TicketIssueService;

/**
 * Questions to the store and their answers (docs/MAINTENANCE.md §2).
 */
class IssueInfoRequestController extends Controller
{
    public function __construct(
        private InfoRequestService $requests,
        private TicketIssueService $issues,
    ) {
    }

    public function store(IssueInfoRequestStoreRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): array
    {
        $this->requests->ask($ticketIssue, $request->validated('question'), (array) $request->file('files', []), $request->user());

        return $this->issues->respond($ticketIssue);
    }

    public function answer(IssueInfoAnswerRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue, IssueInfoRequest $infoRequest): array
    {
        $this->requests->answer(
            $ticketIssue,
            $infoRequest,
            $request->validated('answer'),
            $request->validated('answered_by_name'),
            (array) $request->file('files', []),
            $request->user(),
        );

        return $this->issues->respond($ticketIssue);
    }
}
