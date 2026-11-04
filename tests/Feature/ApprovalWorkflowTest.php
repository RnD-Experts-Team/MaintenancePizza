<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\IssueApproval;
use App\Models\IssueInspection;
use App\Models\MaintenanceSetting;
use App\Models\TicketIssue;
use App\Services\MaintenanceEvents\IssueEventPublisher;
use Illuminate\Http\UploadedFile;

/**
 * Asking for and recording approval of a quote (docs/MAINTENANCE.md §2).
 * The level comes from the amount and maintenance_settings.approval_levels
 * (max is an exclusive upper bound; the last level has none).
 */
class ApprovalWorkflowTest extends IssueWorkflowTestCase
{
    public function test_asking_for_approval_waits_for_the_approver_at_the_level_of_the_amount(): void
    {
        $this->putStatus(IssueStatus::Inspection);

        $this->act('approval-request', ['amount' => '450'])->assertOk()
            ->assertJsonPath('data.status', 'waiting_approval')
            ->assertJsonPath('data.step', 7)
            ->assertJsonPath('data.whose_turn', 'approver')
            ->assertJsonPath('data.next_action', 'Waiting for Maintenance Manager to approve $450.00')
            ->assertJsonPath('data.workflow.next.0.action', 'approval');

        $events = $this->outbox(IssueEventPublisher::APPROVAL_NEEDED);
        $this->assertCount(1, $events);
        $this->assertSame('manager', $events[0]->payload['data']['level']);
        $this->assertSame('Maintenance Manager', $events[0]->payload['data']['level_label']);
        $this->assertSame('450.00', $events[0]->payload['data']['amount']);
    }

    public function test_the_level_follows_the_default_thresholds_with_exclusive_maxes(): void
    {
        $cases = ['299.99' => 'specialist', '300' => 'manager', '999.99' => 'manager', '1000' => 'management', '25000' => 'management'];

        foreach ($cases as $amount => $level) {
            $issue = TicketIssue::factory()->for($this->ticket)->create(['status' => 'in_review']);
            $this->act('approval-request', ['amount' => $amount], $issue)->assertOk();

            $this->assertSame($level, collect($this->outbox(IssueEventPublisher::APPROVAL_NEEDED))->last()->payload['data']['level'], "amount {$amount}");
        }
    }

    public function test_the_level_follows_the_settings_when_they_are_changed(): void
    {
        MaintenanceSetting::put('approval_levels', [
            ['max' => 100, 'level' => 'specialist', 'label' => 'Specialist'],
            ['max' => null, 'level' => 'owner', 'label' => 'The owner'],
        ]);
        $this->putStatus(IssueStatus::InReview);

        $this->act('approvals', ['amount' => '150', 'decision' => 'approved', 'approver_name' => 'Pat Owner'])->assertOk()
            ->assertJsonPath('data.approvals.0.level', 'owner')
            ->assertJsonPath('data.approvals.0.level_label', 'The owner');
    }

    public function test_recording_an_approval_goes_back_to_review_to_schedule_the_work(): void
    {
        $this->putStatus(IssueStatus::WaitingApproval);
        $inspection = IssueInspection::query()->create([
            'ticket_issue_id' => $this->issue->id, 'technician_id' => $this->technician->id,
            'status' => 'done', 'estimated_cost' => 450,
        ]);

        $this->post($this->url('/approvals'), [
            'amount' => '450',
            'decision' => 'approved',
            'approver_name' => 'Rita Manager',
            'note' => 'Go ahead',
            'inspection_id' => $inspection->id,
            'files' => [UploadedFile::fake()->image('ok.png')],
        ], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.next_action', 'Schedule the work')
            ->assertJsonPath('data.approvals.0.amount', '450.00')
            ->assertJsonPath('data.approvals.0.level', 'manager')
            ->assertJsonPath('data.approvals.0.level_label', 'Maintenance Manager')
            ->assertJsonPath('data.approvals.0.decision', 'approved')
            ->assertJsonPath('data.approvals.0.approver_name', 'Rita Manager')
            ->assertJsonPath('data.approvals.0.inspection_id', $inspection->id)
            ->assertJsonPath('data.approvals.0.attachments.0.original_name', 'ok.png')
            ->assertJsonPath('data.costs.approved', '450.00')
            ->assertJsonPath('data.workflow.next.0.action', 'schedule');
    }

    public function test_a_rejection_asks_to_find_another_option(): void
    {
        $this->putStatus(IssueStatus::WaitingApproval);

        $this->act('approvals', ['amount' => '2000', 'decision' => 'rejected', 'approver_name' => 'Board'])->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.next_action', 'Find another option')
            ->assertJsonPath('data.approvals.0.level', 'management')
            ->assertJsonPath('data.costs.approved', null)
            ->assertJsonPath('data.workflow.next.0.action', 'inspection');
    }

    public function test_an_inspection_from_another_issue_is_refused(): void
    {
        $other = TicketIssue::factory()->for($this->ticket)->create();
        $inspection = IssueInspection::query()->create(['ticket_issue_id' => $other->id, 'status' => 'done']);
        $this->putStatus(IssueStatus::InReview);

        $this->act('approval-request', ['amount' => 10, 'inspection_id' => $inspection->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['inspection_id']);
    }

    public function test_asking_is_refused_once_scheduled_and_recording_is_refused_on_a_new_issue(): void
    {
        $this->putStatus(IssueStatus::Scheduled);
        $this->assertRefused($this->act('approval-request', ['amount' => 10]));

        $this->putStatus(IssueStatus::New);
        $this->assertRefused($this->act('approvals', ['amount' => 10, 'decision' => 'approved', 'approver_name' => 'x']))
            ->assertJsonPath('errors.status.0', 'There is no quote to decide on while the issue is new.');
        $this->assertSame(0, IssueApproval::query()->count());
    }
}
