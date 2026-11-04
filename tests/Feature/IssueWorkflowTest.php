<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\TicketStatus;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\TripStopIssue;
use App\Services\MaintenanceEvents\IssueEventPublisher;
use App\Services\TicketStatusService;
use Illuminate\Http\UploadedFile;

/**
 * The status machine of one issue over HTTP (docs/MAINTENANCE.md §2):
 * review, work done, the store's confirmation, close with its checklist, the
 * jump escape hatch, cancel, and the outbox event every change writes.
 */
class IssueWorkflowTest extends IssueWorkflowTestCase
{
    // ---------------------------------------------------------------- Review

    public function test_review_moves_a_new_issue_into_review_with_its_plan(): void
    {
        $this->act('review', [
            'target_completion_at' => '2026-11-05 17:00:00',
            'next_action' => 'Call the store',
            'assigned_priority' => 'high',
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.status_label', 'In review')
            ->assertJsonPath('data.step', 2)
            ->assertJsonPath('data.next_action', 'Call the store')
            ->assertJsonPath('data.assigned_priority.value', 'high')
            ->assertJsonPath('data.workflow.step', 2);

        $this->assertStatus(IssueStatus::InReview);
    }

    public function test_review_while_in_review_only_updates_the_fields(): void
    {
        $this->putStatus(IssueStatus::InReview);

        $this->act('review', ['next_action' => 'Order the part'])->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.next_action', 'Order the part');

        $this->assertSame(0, $this->issue->statusChanges()->count());
    }

    public function test_review_updates_the_plan_in_any_open_status_without_moving_it(): void
    {
        foreach ([IssueStatus::WaitingOnStore, IssueStatus::Inspection, IssueStatus::WaitingApproval, IssueStatus::Scheduled, IssueStatus::WorkDone] as $status) {
            $this->putStatus($status);

            $this->act('review', [
                'target_completion_at' => '2026-11-09 12:00:00',
                'next_action' => "Plan while {$status->value}",
                'assigned_priority' => 'urgent',
            ])->assertOk()
                ->assertJsonPath('data.status', $status->value)
                ->assertJsonPath('data.next_action', "Plan while {$status->value}")
                ->assertJsonPath('data.assigned_priority.value', 'urgent');

            $this->assertStatus($status);
        }

        $this->assertSame(0, $this->issue->statusChanges()->count());
        $this->assertCount(0, $this->outbox(IssueEventPublisher::STATUS_CHANGED));
    }

    public function test_review_is_refused_once_the_issue_is_finished(): void
    {
        $this->putStatus(IssueStatus::Closed);

        $this->assertRefused($this->act('review', ['next_action' => 'x']))
            ->assertJsonPath('errors.status.0', 'This issue is closed. Change its status first if it needs more work.');
    }

    // ------------------------------------------------------------- Work done

    public function test_work_done_records_the_work_photos_warranty_and_ticks_the_trip_task(): void
    {
        $this->act('schedule', ['technician_id' => $this->technician->id, 'date' => '2026-11-03'])->assertOk();

        $this->post($this->url('/work-done'), [
            'work_performed' => 'Replaced the fan motor',
            'files' => [UploadedFile::fake()->image('after.jpg')],
            'warranty' => ['body' => 'One year on the motor', 'expiry_date' => '2027-11-03'],
        ], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'work_done')
            ->assertJsonPath('data.work_performed', 'Replaced the fan motor')
            ->assertJsonPath('data.attachments.0.original_name', 'after.jpg')
            ->assertJsonPath('data.warranties.0.body', 'One year on the motor')
            ->assertJsonPath('data.warranties.0.expiry_date', '2027-11-03')
            ->assertJsonPath('data.visits.0.task_status', 'done');

        $this->assertSame('done', TripStopIssue::query()->where('ticket_issue_id', $this->issue->id)->value('task_status'));
    }

    public function test_work_done_is_refused_before_anything_is_scheduled(): void
    {
        $this->assertRefused($this->act('work-done', ['work_performed' => 'x']))
            ->assertJsonPath('errors.status.0', 'Work can be recorded as done once it is scheduled; this issue is new.');
        $this->assertStatus(IssueStatus::New);
    }

    // ---------------------------------------------------------- Confirmation

    public function test_the_store_confirming_it_works_keeps_it_ready_to_close(): void
    {
        $this->putStatus(IssueStatus::WorkDone);

        $this->act('confirmation', ['working' => true])->assertOk()
            ->assertJsonPath('data.status', 'work_done')
            ->assertJsonPath('data.store_confirmed_working', true)
            ->assertJsonPath('data.store_confirmed_by_name', null)
            ->assertJsonPath('data.store_confirmed_by.id', $this->authUser->id)
            ->assertJsonPath('data.workflow.next.0.action', 'close')
            ->assertJsonPath('data.workflow.checklist.0.key', 'resolved')
            ->assertJsonPath('data.workflow.checklist.0.done', true);
    }

    public function test_not_working_sends_it_back_to_review_with_schedule_more_work(): void
    {
        $this->putStatus(IssueStatus::WorkDone);

        $this->act('confirmation', ['working' => false, 'note' => 'Still warm at 2pm', 'confirmed_by_name' => 'Sara, by phone'])->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.next_action', 'Schedule more work')
            ->assertJsonPath('data.store_confirmed_working', false)
            ->assertJsonPath('data.store_confirmed_by_name', 'Sara, by phone')
            ->assertJsonPath('data.notes.0.body', 'Still warm at 2pm');

        $this->assertSame(
            'The store says (Sara, by phone) it still does not work: Still warm at 2pm',
            $this->issue->statusChanges()->latest('id')->value('reason'),
        );
    }

    public function test_confirmation_is_refused_before_the_work_is_done(): void
    {
        $this->putStatus(IssueStatus::Scheduled);

        $this->assertRefused($this->act('confirmation', ['working' => true]));
        $this->assertNull($this->issue->fresh()->store_confirmed_at);
    }

    // ----------------------------------------------------------------- Close

    public function test_close_refuses_an_incomplete_checklist_and_says_what_is_missing(): void
    {
        $this->putStatus(IssueStatus::WorkDone);

        $this->assertRefused($this->act('close', ['resolution' => 'repaired', 'final_note' => 'The fan motor was burnt; replaced it.']))
            ->assertJsonPath('errors.status.0', 'Not done yet: the store says it works, cost recorded, invoice attached, work notes written. Finish these first, or give a reason to close anyway.')
            ->assertJsonPath('errors.checklist', ['resolved', 'cost_recorded', 'invoice_attached', 'notes_completed']);

        $this->assertStatus(IssueStatus::WorkDone);
    }

    public function test_close_with_override_reason_closes_and_logs_what_was_skipped(): void
    {
        $this->putStatus(IssueStatus::WorkDone);

        $this->act('close', [
            'resolution' => 'repaired',
            'override_reason' => 'Paid from petty cash, no invoice',
            'final_note' => 'Motor swapped',
            'what_we_learned' => 'Check the fan first',
        ])->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.step', 11)
            ->assertJsonPath('data.resolution.value', 'repaired');

        $issue = $this->issue->fresh();
        $this->assertNotNull($issue->closed_at);
        $this->assertSame(
            'Closed without: The store says it works, Cost recorded, Invoice attached, Work notes written. Reason: Paid from petty cash, no invoice',
            $issue->statusChanges()->latest('id')->value('reason'),
        );
        $this->assertEqualsCanonicalizing(
            ['final_notes', 'what_we_learned'],
            $issue->notes()->pluck('type')->all(),
        );
    }

    public function test_close_passes_when_the_checklist_is_complete(): void
    {
        $this->putStatus(IssueStatus::WorkDone);
        $this->issue->forceFill(['store_confirmed_working' => true, 'store_confirmed_at' => now(), 'work_performed' => 'Fixed'])->save();

        $payment = Payment::query()->create(['paid_at' => '2026-10-29', 'technician_id' => $this->technician->id, 'total' => 120]);
        $line = PaymentLine::query()->create(['payment_id' => $payment->id, 'type' => 'fixed', 'store_id' => $this->store->id, 'amount' => 120]);
        $line->ticketIssues()->attach($this->issue->id);

        $this->act('close', ['resolution' => 'repaired', 'final_note' => 'The fan motor was burnt; replaced it.'])->assertUnprocessable()
            ->assertJsonPath('errors.checklist', ['invoice_attached']);

        $payment->attachments()->create(['path' => 'attachments/invoice.pdf', 'original_name' => 'invoice.pdf']);

        $this->read()->assertJsonPath('data.workflow.checklist.2', ['key' => 'invoice_attached', 'label' => 'Invoice attached', 'done' => true]);

        $this->act('close', ['resolution' => 'repaired', 'final_note' => 'The fan motor was burnt; replaced it.'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertNull($this->issue->statusChanges()->latest('id')->value('reason'));
    }

    public function test_a_resolution_without_paid_work_needs_nothing_on_the_checklist(): void
    {
        $this->act('close', ['resolution' => 'fixed_by_troubleshooting', 'final_note' => 'The fan motor was burnt; replaced it.'])->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.workflow.checklist.1.done', true);
    }

    public function test_close_is_refused_on_a_closed_issue(): void
    {
        $this->putStatus(IssueStatus::Closed);

        $this->assertRefused($this->act('close', ['resolution' => 'not_needed', 'override_reason' => 'x', 'final_note' => 'The fan motor was burnt; replaced it.']))
            ->assertJsonPath('errors.status.0', 'This issue is closed. Change its status first if it needs more work.');
    }

    // ------------------------------------------------------------------ Jump

    public function test_jump_needs_a_reason(): void
    {
        $this->act('jump', ['status' => 'scheduled'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->assertStatus(IssueStatus::New);
    }

    public function test_jump_goes_anywhere_and_is_logged(): void
    {
        $this->putStatus(IssueStatus::Closed);
        $this->issue->forceFill(['closed_at' => now()])->save();

        $this->act('jump', ['status' => 'in_review', 'reason' => 'Store called, it broke again'])->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.closed_at', null)
            ->assertJsonPath('data.status_changes.0.reason', 'Changed by hand: Store called, it broke again')
            ->assertJsonPath('data.status_changes.0.from_status', 'closed');
    }

    public function test_jump_to_the_same_status_is_refused(): void
    {
        $this->assertRefused($this->act('jump', ['status' => 'new', 'reason' => 'x']))
            ->assertJsonPath('errors.status.0', 'The issue is already new.');
    }

    // ---------------------------------------------------------------- Cancel

    public function test_cancel_still_works_and_refuses_a_finished_issue(): void
    {
        $this->putStatus(IssueStatus::Scheduled);

        $this->act('cancel', ['reason' => 'Store closing'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertRefused($this->act('cancel', ['reason' => 'again']));
    }

    public function test_cancelling_a_ticket_leaves_closed_issues_closed(): void
    {
        $this->putStatus(IssueStatus::Closed);
        $open = TicketIssue::factory()->for($this->ticket)->create(['status' => 'scheduled']);

        $this->postJson("/api/stores/{$this->store->store_number}/tickets/{$this->ticket->id}/cancel", ['reason' => 'Duplicate'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status.value', 'closed');

        $this->assertStatus(IssueStatus::Closed);
        $this->assertStatus(IssueStatus::Cancelled, $open);
    }

    public function test_assigned_priority_still_works(): void
    {
        $this->act('assigned-priority', ['priority' => 'urgent'])->assertOk()
            ->assertJsonPath('data.assigned_priority.value', 'urgent')
            ->assertJsonPath('data.priority.value', 'medium');
    }

    // ---------------------------------------------------------------- Outbox

    public function test_every_status_change_writes_a_status_changed_event(): void
    {
        $this->act('review')->assertOk();
        $this->act('jump', ['status' => 'scheduled', 'reason' => 'Booked by phone'])->assertOk();

        $events = $this->outbox(IssueEventPublisher::STATUS_CHANGED);
        $this->assertCount(2, $events);

        $data = $events[1]->payload['data'];
        $this->assertSame('in_review', $data['from']);
        $this->assertSame('scheduled', $data['to']);
        $this->assertSame('technician', $data['whose_turn']);
        $this->assertSame('Changed by hand: Booked by phone', $data['reason']);
        $this->assertSame($this->issue->id, $data['issue']['id']);
        $this->assertSame('03795-00001', $data['issue']['store_number']);
        $this->assertSame('maintenance.issue.status_changed', $events[1]->payload['type']);
    }

    public function test_review_while_in_review_writes_no_status_event(): void
    {
        $this->putStatus(IssueStatus::InReview);
        $this->act('review', ['next_action' => 'x'])->assertOk();

        $this->assertCount(0, $this->outbox(IssueEventPublisher::STATUS_CHANGED));
    }

    // ------------------------------------------------------ Derived status

    public function test_the_ticket_status_is_derived_from_a_mix_of_issue_statuses(): void
    {
        $ticket = Ticket::factory()->for($this->store)->create();
        $make = fn (string $status) => TicketIssue::factory()->for($ticket)->create(['status' => $status]);
        $status = fn () => TicketStatusService::for($ticket->fresh());

        $make('closed');
        $make('cancelled');
        $this->assertSame(TicketStatus::Closed, $status());

        $make('new');
        $this->assertSame(TicketStatus::New, $status());

        $make('work_done');
        $this->assertSame(TicketStatus::WorkDone, $status());

        $make('inspection');
        $this->assertSame(TicketStatus::Inspection, $status());

        $make('scheduled');
        $this->assertSame(TicketStatus::Scheduled, $status());

        $make('waiting_approval');
        $this->assertSame(TicketStatus::WaitingApproval, $status());

        $make('waiting_on_store');
        $this->assertSame(TicketStatus::WaitingOnStore, $status());

        $all = Ticket::factory()->for($this->store)->create();
        TicketIssue::factory()->for($all)->count(2)->create(['status' => 'cancelled']);
        $this->assertSame(TicketStatus::Cancelled, TicketStatusService::for($all));

        // The SQL twin agrees.
        $this->getJson("/api/stores/{$this->store->store_number}/tickets?statuses[]=waiting_on_store", $this->headers())
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $ticket->id)
            ->assertJsonPath('data.0.issues.0.status', 'closed');
    }
}
