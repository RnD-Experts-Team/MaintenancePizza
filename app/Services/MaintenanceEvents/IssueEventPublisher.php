<?php

namespace App\Services\MaintenanceEvents;

use App\Enums\IssueStatus;
use App\Jobs\PublishOutboxEventJob;
use App\Models\IssueInfoRequest;
use App\Models\MaintenanceOutboxEvent;
use App\Models\Ticket;
use App\Models\TicketIssue;

/**
 * The domain events of the issue workflow (docs/MAINTENANCE.md §2), written
 * to the outbox in the same transaction as the change they describe and
 * published after commit (PublishOutboxEventJob; PublishPendingOutboxCommand
 * sweeps anything a lost job left behind).
 *
 *   maintenance.issue.status_changed   every status change, whoever made it
 *   maintenance.issue.info_requested   the office asked the store something
 *   maintenance.issue.info_answered    the store (or the specialist for it) answered
 *   maintenance.issue.approval_needed  a quote waits for someone at `level`
 *
 * Every payload carries the same `issue` block, so a consumer can route on
 * the store without reading anything back.
 */
class IssueEventPublisher
{
    public const STATUS_CHANGED = 'maintenance.issue.status_changed';

    public const INFO_REQUESTED = 'maintenance.issue.info_requested';

    public const INFO_ANSWERED = 'maintenance.issue.info_answered';

    public const APPROVAL_NEEDED = 'maintenance.issue.approval_needed';

    public function __construct(
        private MaintenanceEventFactory $factory,
        private MaintenanceOutboxService $outbox,
    ) {
    }

    public function statusChanged(TicketIssue $issue, ?IssueStatus $from, IssueStatus $to, ?string $reason): MaintenanceOutboxEvent
    {
        return $this->record(self::STATUS_CHANGED, [
            'issue' => $this->issueBlock($issue),
            'from' => $from?->value,
            'from_label' => $from?->label(),
            'to' => $to->value,
            'to_label' => $to->label(),
            'whose_turn' => $to->whoseTurn(),
            'reason' => $reason,
            'changed_by' => auth()->id(),
        ]);
    }

    public function infoRequested(TicketIssue $issue, IssueInfoRequest $request): MaintenanceOutboxEvent
    {
        return $this->record(self::INFO_REQUESTED, [
            'issue' => $this->issueBlock($issue),
            'info_request_id' => $request->id,
            'question' => $request->question,
            'asked_by' => $request->asked_by,
        ]);
    }

    public function infoAnswered(TicketIssue $issue, IssueInfoRequest $request): MaintenanceOutboxEvent
    {
        return $this->record(self::INFO_ANSWERED, [
            'issue' => $this->issueBlock($issue),
            'info_request_id' => $request->id,
            'question' => $request->question,
            'answer' => $request->answer,
            'answered_by_name' => $request->answered_by_name,
            'answered_by' => $request->answered_by,
        ]);
    }

    /**
     * @param  array{amount: string, level: string, label: string}  $level
     */
    public function approvalNeeded(TicketIssue $issue, array $level, ?int $inspectionId): MaintenanceOutboxEvent
    {
        return $this->record(self::APPROVAL_NEEDED, [
            'issue' => $this->issueBlock($issue),
            'amount' => $level['amount'],
            'level' => $level['level'],
            'level_label' => $level['label'],
            'inspection_id' => $inspectionId,
            'requested_by' => auth()->id(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(string $subject, array $data): MaintenanceOutboxEvent
    {
        $envelope = $this->factory->make($subject, $data, request());
        $row = $this->outbox->record($subject, $envelope);

        PublishOutboxEventJob::dispatch($row->id)->afterCommit();

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function issueBlock(TicketIssue $issue): array
    {
        /** @var Ticket|null $ticket */
        $ticket = $issue->relationLoaded('ticket')
            ? $issue->ticket
            : $issue->ticket()->withTrashed()->with('store')->first();

        return [
            'id' => $issue->id,
            'ticket_id' => $issue->ticket_id,
            'store_number' => $ticket?->store?->store_number,
            'other_store' => $ticket?->other_store,
            'title' => $issue->displayTitle(),
            'status' => $issue->status?->value,
            'priority' => $issue->priority?->value,
            'assigned_priority' => $issue->assigned_priority?->value,
        ];
    }
}
