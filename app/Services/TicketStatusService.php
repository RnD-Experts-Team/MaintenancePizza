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
 *   1. any issue In Progress                       -> In Progress
 *   2. else any issue Assigned                     -> Assigned
 *   3. else every issue Cancelled                  -> Cancelled
 *   4. else all issues Complete/Deferred/Cancelled -> Complete
 *   5. else (incl. no issues)                      -> Pending
 */
class TicketStatusService
{
    public static function for(Ticket $ticket): TicketStatus
    {
        // Use the already-loaded relation when present to avoid extra queries.
        $statuses = $ticket->relationLoaded('ticketIssues')
            ? $ticket->ticketIssues->pluck('status')
            : $ticket->ticketIssues()->pluck('status')->map(fn($s) => IssueStatus::from($s));

        if ($statuses->isEmpty()) {
            return TicketStatus::Pending;
        }

        if ($statuses->contains(IssueStatus::InProgress)) {
            return TicketStatus::InProgress;
        }

        if ($statuses->contains(IssueStatus::Assigned)) {
            return TicketStatus::Assigned;
        }

        // All issues cancelled -> the ticket itself is cancelled (checked before
        // the all-terminal rollup, since Cancelled is now a terminal status).
        if ($statuses->every(fn(IssueStatus $s) => $s === IssueStatus::Cancelled)) {
            return TicketStatus::Cancelled;
        }

        $terminal = IssueStatus::terminal();
        $allTerminal = $statuses->every(fn(IssueStatus $s) => in_array($s, $terminal, true));

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
