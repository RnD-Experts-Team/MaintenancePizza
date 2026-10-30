<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\MaintenanceEvents\IssueEventPublisher;

/**
 * Derives a ticket's status from its issues. The ticket has no stored status
 * column — it is always computed, so it can never drift out of sync.
 *
 * Precedence (docs/MAINTENANCE-V2.md §2), first match wins -- whatever is
 * waiting on someone outside the office shows first:
 *   1. any issue waiting_on_store   -> waiting_on_store
 *   2. any issue waiting_approval   -> waiting_approval
 *   3. any issue scheduled          -> scheduled
 *   4. any issue inspection         -> inspection
 *   5. any issue work_done          -> work_done
 *   6. any issue in_review          -> in_review
 *   7. any issue new                -> new
 *   8. every issue cancelled        -> cancelled
 *   9. otherwise (closed, or closed + cancelled) -> closed
 * A ticket with no issues at all reads as new: nothing about it is done.
 *
 * TicketService::applyDerivedStatusClause() is the SQL twin of this and must
 * follow the same order.
 */
class TicketStatusService
{
    /**
     * The open statuses in the order they win. The SQL twin reads this too.
     *
     * @var array<int, IssueStatus>
     */
    public const PRECEDENCE = [
        IssueStatus::WaitingOnStore,
        IssueStatus::WaitingApproval,
        IssueStatus::Scheduled,
        IssueStatus::Inspection,
        IssueStatus::WorkDone,
        IssueStatus::InReview,
        IssueStatus::New,
    ];

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
            return TicketStatus::New;
        }

        foreach (self::PRECEDENCE as $issueStatus) {
            if ($statuses->contains($issueStatus)) {
                return TicketStatus::from($issueStatus->value);
            }
        }

        return $statuses->every(fn (IssueStatus $s) => $s === IssueStatus::Cancelled)
            ? TicketStatus::Cancelled
            : TicketStatus::Closed;
    }

    /**
     * Transition a single issue's status, recording an audit row (with the
     * acting user and an optional reason). The ticket status is derived, so it
     * needs no explicit update here.
     *
     * Every real change (from != to) also writes the outbox event
     * maintenance.issue.status_changed, in the caller's transaction -- this
     * is the one door every status change goes through (workflow actions,
     * cancel, jump, the technician's trip link), so nothing is missed.
     */
    public static function changeIssueStatus(TicketIssue $issue, IssueStatus $to, ?string $reason = null): void
    {
        $from = $issue->status;

        $issue->statusChanges()->create([
            'from_status' => $issue->status,
            'to_status' => $to,
            'reason' => $reason,
            'created_by' => auth()->id(),
        ]);

        // closed_at is when the issue last finished; reopening clears it.
        if ($to->isTerminal()) {
            $issue->closed_at ??= now();
        } else {
            $issue->closed_at = null;
        }

        $issue->status = $to;
        $issue->save();

        if ($from !== $to) {
            app(IssueEventPublisher::class)->statusChanged($issue, $from, $to, $reason);
        }
    }
}
