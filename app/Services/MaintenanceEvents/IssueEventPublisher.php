<?php

namespace App\Services\MaintenanceEvents;

use App\Enums\IssueStatus;
use App\Jobs\PublishOutboxEventJob;
use App\Models\IssueInfoRequest;
use App\Models\MaintenanceOutboxEvent;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Support\Str;

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
 * Plus one notification (notifications.v1.notification.role.send): a
 * question for the store goes straight to its Store Managers.
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

    public const NOTIFY_ROLE = 'notifications.v1.notification.role.send';

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

    /**
     * The office asked the store a question: its Store Managers hear about it
     * now, not in the next update digest. The MOS may answer it for them after
     * a call, but the store is who is asked. A ticket at no store (storage, a
     * place written in words) has no Store Managers to tell.
     */
    public function questionForStore(TicketIssue $issue, IssueInfoRequest $request): ?MaintenanceOutboxEvent
    {
        $storeNumber = $this->issueBlock($issue)['store_number'];
        if ($storeNumber === null) {
            return null;
        }

        return $this->record(self::NOTIFY_ROLE, [
            'channels' => ['web'],
            'roles' => ['Store Manager'],
            'stores' => [$storeNumber],
            'payload' => [
                'type' => 'maintenance_question_for_store',
                'title' => "Ticket #{$issue->ticket_id} for Store {$storeNumber}: the office has a question",
                'body' => ($issue->displayTitle() ?: 'Issue').': "'.Str::limit($request->question, 200).'"',
                'action_url' => "/dashboard/maintenance-tickets/{$issue->ticket_id}?store={$storeNumber}&issue={$issue->id}",
            ],
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
            : $issue->ticket()->withTrashed()->with('store', 'storageLocation')->first();

        return [
            'id' => $issue->id,
            'ticket_id' => $issue->ticket_id,
            'store_number' => $ticket?->store?->store_number,
            'other_store' => $ticket?->other_store,
            'storage_location' => $ticket?->storageLocation?->name,
            'title' => $issue->displayTitle(),
            'status' => $issue->status?->value,
            'priority' => $issue->priority?->value,
            'assigned_priority' => $issue->assigned_priority?->value,
        ];
    }
}
