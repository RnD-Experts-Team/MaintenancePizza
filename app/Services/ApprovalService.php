<?php

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\IssueStatus;
use App\Models\IssueApproval;
use App\Models\TicketIssue;
use App\Models\User;
use App\Services\MaintenanceEvents\IssueEventPublisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Approving a quote (docs/MAINTENANCE-V2.md §2). Who has to approve depends on
 * the amount (maintenance_settings.approval_levels): the specialist up to one
 * amount, the manager up to the next, management above. Asking makes it the
 * approver's turn; the decision -- by NAME today, the approver may not have a
 * login -- hands the issue back to review with what to do next.
 */
class ApprovalService
{
    public const NEXT_AFTER_APPROVED = 'Schedule the work';

    public const NEXT_AFTER_REJECTED = 'Find another option';

    public function __construct(
        private IssueWorkflowService $workflow,
        private AttachmentService $attachments,
        private IssueEventPublisher $events,
    ) {
    }

    /**
     * @return array{amount: string, level: string, label: string}
     */
    public function request(TicketIssue $issue, string|float $amount, ?int $inspectionId): array
    {
        return DB::transaction(function () use ($issue, $amount, $inspectionId) {
            $issue = $this->workflow->lock($issue);
            $this->workflow->assertCan($issue, IssueWorkflowService::APPROVAL_REQUEST);

            $level = $this->workflow->approvalLevelFor($amount);

            $issue->next_action = "Waiting for {$level['label']} to approve {$level['amount']}";
            $issue->save();

            if ($issue->status !== IssueStatus::WaitingApproval) {
                TicketStatusService::changeIssueStatus(
                    $issue,
                    IssueStatus::WaitingApproval,
                    "Approval needed for {$level['amount']} ({$level['label']})",
                );
            }

            $this->events->approvalNeeded($issue, $level, $inspectionId);

            return $level;
        });
    }

    /**
     * @param  array{amount: string|float, decision: string, approver_name: string, note?: string|null, inspection_id?: int|null}  $data
     * @param  array<int, UploadedFile>  $files  the approval screenshot, etc.
     */
    public function record(TicketIssue $issue, array $data, array $files, User $by): IssueApproval
    {
        return DB::transaction(function () use ($issue, $data, $files, $by) {
            $issue = $this->workflow->lock($issue);
            $this->workflow->assertCan($issue, IssueWorkflowService::APPROVAL);

            $level = $this->workflow->approvalLevelFor($data['amount']);
            $decision = ApprovalDecision::from($data['decision']);

            $approval = IssueApproval::query()->create([
                'ticket_issue_id' => $issue->id,
                'issue_inspection_id' => $data['inspection_id'] ?? null,
                'amount' => $level['amount'],
                'level' => $level['level'],
                'decision' => $decision,
                'approver_name' => trim($data['approver_name']),
                'decided_at' => now(),
                'note' => $data['note'] ?? null,
                'created_by' => $by->id,
            ]);

            $this->attachments->store($approval, $files);

            $issue->next_action = $decision === ApprovalDecision::Approved ? self::NEXT_AFTER_APPROVED : self::NEXT_AFTER_REJECTED;
            $issue->save();

            $reason = "{$decision->label()} by {$approval->approver_name} ({$level['label']}) for {$level['amount']}";
            TicketStatusService::changeIssueStatus($issue, IssueStatus::InReview, $reason);

            return $approval;
        });
    }
}
