<?php

namespace Tests\Feature;

use App\Models\Diagnosis;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\WorkflowRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * /stores/{store}/tickets/{ticket}/diagnoses/{diagnosis}/... is not
 * scope-bound, and pizzasys only authorises the STORE in the URL. Without the
 * check in the leaf requests (ChecksTicketRecord), a user cleared for their own
 * store could act on any other store's records by putting their store in front
 * of someone else's ids.
 */
class LeafRouteOwnershipTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $mine;

    private Ticket $myTicket;

    private Ticket $theirTicket;

    private int $theirDiagnosisId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();

        $this->mine = Store::factory()->create();
        $theirs = Store::factory()->create();
        $this->myTicket = Ticket::factory()->for($this->mine)->create();
        TicketIssue::factory()->for($this->myTicket)->create();
        $this->theirTicket = Ticket::factory()->for($theirs)->create();
        $theirIssue = TicketIssue::factory()->for($this->theirTicket)->create();

        $this->theirDiagnosisId = app(WorkflowRecordService::class)
            ->createDiagnosis([$theirIssue->id], 'theirs', [])['id'];
    }

    public function test_another_stores_ticket_cannot_be_reached_through_my_store(): void
    {
        $this->postJson(
            "/api/stores/{$this->mine->store_number}/tickets/{$this->theirTicket->id}/diagnoses/{$this->theirDiagnosisId}/mistaken",
            [],
            $this->headers(),
        )->assertNotFound();

        $this->assertFalse(Diagnosis::findOrFail($this->theirDiagnosisId)->mistaken);
    }

    public function test_a_record_from_another_ticket_cannot_be_reached_through_my_ticket(): void
    {
        $this->postJson(
            "/api/stores/{$this->mine->store_number}/tickets/{$this->myTicket->id}/diagnoses/{$this->theirDiagnosisId}/notes",
            ['body' => 'sneaky'],
            $this->headers(),
        )->assertNotFound();

        $this->assertSame(0, Diagnosis::findOrFail($this->theirDiagnosisId)->notes()->count());
    }

    public function test_my_own_record_on_my_own_ticket_goes_through(): void
    {
        $issue = $this->myTicket->ticketIssues()->first();
        $mine = app(WorkflowRecordService::class)->createDiagnosis([$issue->id], 'mine', [])['id'];

        $this->postJson(
            "/api/stores/{$this->mine->store_number}/tickets/{$this->myTicket->id}/diagnoses/{$mine}/mistaken",
            [],
            $this->headers(),
        )->assertSuccessful();
    }

    public function test_a_visit_spanning_two_of_my_tickets_is_reachable_through_either(): void
    {
        $second = Ticket::factory()->for($this->mine)->create();
        $issueA = $this->myTicket->ticketIssues()->first();
        $issueB = TicketIssue::factory()->for($second)->create();
        $tech = Technician::factory()->create();
        $issueA->technicians()->attach($tech->id);

        $entryId = app(WorkflowRecordService::class)->createAttendance([
            'technician_id' => $tech->id,
            'ticket_issue_ids' => [$issueA->id, $issueB->id],
            'start_clock' => '2026-10-06 09:00:00',
        ], [], [])['id'];

        foreach ([$this->myTicket, $second] as $ticket) {
            $this->postJson(
                "/api/stores/{$this->mine->store_number}/tickets/{$ticket->id}/attendance-entries/{$entryId}/notes",
                ['body' => 'on the way'],
                $this->headers(),
            )->assertCreated();
        }
    }
}
