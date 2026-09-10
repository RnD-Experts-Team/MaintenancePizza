<?php

namespace Tests\Feature;

use App\Models\AttendanceEntry;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\WorkflowRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Tests\TestCase;

class AttendanceEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_entry_can_cover_issues_from_several_tickets(): void
    {
        $technician = Technician::factory()->create();
        $ticketA = Ticket::factory()->create();
        $ticketB = Ticket::factory()->create();
        $issueA = TicketIssue::factory()->for($ticketA)->create();
        $issueB = TicketIssue::factory()->for($ticketB)->create();
        $technician->ticketIssues()->attach([$issueA->id, $issueB->id]);

        $result = app(WorkflowRecordService::class)->createAttendance([
            'technician_id' => $technician->id,
            'ticket_issue_ids' => [$issueA->id, $issueB->id],
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 16:00:00',
            'start_travel' => '2026-09-10 07:00:00',
            'end_travel' => '2026-09-10 08:00:00',
            'notes' => [['body' => 'Depot -> store 03795, covered both tickets', 'type' => 'travel']],
        ], [], []);

        $this->assertEqualsCanonicalizing([$issueA->id, $issueB->id], $result['ticket_issue_ids']);
        $this->assertSame(480, $result['durations']['minutes']['work']);
        $this->assertSame(60, $result['durations']['minutes']['travel']);
        $this->assertSame(8.0, $result['durations']['hours']['work']);
        $this->assertSame([], $result['durations']['warnings']);

        $this->assertCount(1, $result['notes']);
        $this->assertSame('travel', $result['notes'][0]['type']);
        $this->assertSame('Depot -> store 03795, covered both tickets', $result['notes'][0]['body']);
    }

    public function test_travel_clocks_round_trip_through_the_database(): void
    {
        $technician = Technician::factory()->create();
        $issue = TicketIssue::factory()->create();
        $technician->ticketIssues()->attach($issue->id);

        app(WorkflowRecordService::class)->createAttendance([
            'technician_id' => $technician->id,
            'ticket_issue_ids' => [$issue->id],
            'start_travel' => '2026-09-10 07:15:00',
            'end_travel' => '2026-09-10 08:00:00',
        ], [], []);

        $entry = AttendanceEntry::sole();

        $this->assertSame('2026-09-10 07:15:00', $entry->start_travel->toDateTimeString());
        $this->assertSame(45, $entry->durations()['travel']);
    }

    /**
     * The nested endpoint accepts issues from other tickets so long as it
     * touches its own; the strict rule other records use is unchanged.
     */
    public function test_nested_request_accepts_a_spanning_entry_but_rejects_a_wholly_foreign_one(): void
    {
        $ownTicket = Ticket::factory()->create();
        $otherTicket = Ticket::factory()->create();
        $own = TicketIssue::factory()->for($ownTicket)->create();
        $foreign = TicketIssue::factory()->for($otherTicket)->create();

        $issues = app(\App\Services\TicketIssueService::class);

        $spanning = ValidatorFacade::make([], []);
        $issues->validateAtLeastOneIssueBelongsToTicket($spanning, $ownTicket, [$own->id, $foreign->id]);
        $this->assertTrue($spanning->errors()->isEmpty(), 'A spanning entry should be allowed.');

        $whollyForeign = ValidatorFacade::make([], []);
        $issues->validateAtLeastOneIssueBelongsToTicket($whollyForeign, $ownTicket, [$foreign->id]);
        $this->assertTrue($whollyForeign->errors()->has('ticket_issue_ids'));

        // The strict check other workflow records rely on still rejects both.
        $strict = ValidatorFacade::make([], []);
        $issues->validateIssuesBelongToTicket($strict, $ownTicket, [$own->id, $foreign->id]);
        $this->assertTrue($strict->errors()->has('ticket_issue_ids'));
    }

    public function test_nonexistent_issue_ids_are_rejected(): void
    {
        $validator = ValidatorFacade::make([], []);
        app(\App\Services\TicketIssueService::class)->validateIssuesExist($validator, [424242]);

        $this->assertStringContainsString('424242', $validator->errors()->first('ticket_issue_ids'));
    }
}
