<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketIssue;

/**
 * Derives a ticket's status from its issues. The ticket has no stored status
 * column — it is always computed, so it can never drift out of sync.
 *
 * Precedence:
 *   1. any issue Waiting                           -> Waiting
 *   2. else any issue In Progress                  -> In Progress
 *   3. else any issue Assigned                     -> Assigned
 *   4. else every issue Cancelled                  -> Cancelled
 *   5. else all issues Complete/Deferred/Cancelled -> Complete
 *   6. else (incl. no issues)                      -> Pending
 */
class TicketStatusService
{
    public static function for(Ticket $ticket): TicketStatus
    {
        // Use the already-loaded relation when present to avoid extra queries.
        //
        // Both branches yield IssueStatus instances: Eloquent's pluck() runs
        // values through the model's casts, so the query branch is ALREADY
        // cast and does not need converting. It used to call IssueStatus::from()
        // on it, which threw a TypeError the moment anything presented a ticket
        // without the relation loaded -- every existing caller happened to have
        // eager-loaded it, so the branch was never exercised.
        //
        // The normalisation below accepts either shape, so neither a future
        // caller nor a change in pluck()'s behaviour can bring the bug back.
        $statuses = ($ticket->relationLoaded('ticketIssues')
            ? $ticket->ticketIssues->pluck('status')
            : $ticket->ticketIssues()->pluck('status')
        )->map(fn ($s) => $s instanceof IssueStatus ? $s : IssueStatus::from($s));

        if ($statuses->isEmpty()) {
            return TicketStatus::Pending;
        }

        if ($statuses->contains(IssueStatus::Waiting)) {
            return TicketStatus::Waiting;
        }

        if ($statuses->contains(IssueStatus::InProgress)) {
            return TicketStatus::InProgress;
        }

        if ($statuses->contains(IssueStatus::Assigned)) {
            return TicketStatus::Assigned;
        }

        // All issues cancelled -> the ticket itself is cancelled (checked before
        // the all-terminal rollup, since Cancelled is now a terminal status).
        if ($statuses->every(fn (IssueStatus $s) => $s === IssueStatus::Cancelled)) {
            return TicketStatus::Cancelled;
        }

        $terminal = IssueStatus::terminal();
        $allTerminal = $statuses->every(fn (IssueStatus $s) => in_array($s, $terminal, true));

        return $allTerminal ? TicketStatus::Complete : TicketStatus::Pending;
    }

    /**
     * Transition a single issue's status, recording an audit row (with the
     * acting user and an optional reason). The ticket status is derived, so it
     * needs no explicit update here.
     */
    public static function changeIssueStatus(TicketIssue $issue, IssueStatus $to, ?string $reason = null): void
    {
        $issue->statusChanges()->create([
            'from_status' => $issue->status,
            'to_status' => $to,
            'reason' => $reason,
            'created_by' => auth()->id(),
        ]);

        $issue->status = $to;
        $issue->save();
    }
}
