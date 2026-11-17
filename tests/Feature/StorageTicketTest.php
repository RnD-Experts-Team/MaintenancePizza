<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\MaintenanceOutboxEvent;
use App\Models\StorageLocation;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\TripStop;
use App\Services\MaintenanceEvents\IssueEventPublisher;

/**
 * A ticket can be for one of our storage locations instead of a store, and a
 * ticket at no store (storage, or a place written in words) runs the same
 * workflow through the storeless twin of the ticket routes.
 */
class StorageTicketTest extends IssueWorkflowTestCase
{
    private StorageLocation $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = StorageLocation::factory()->create(['name' => 'Main storage', 'code' => 'MS']);
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function open(array $place): Ticket
    {
        $id = $this->postJson('/api/tickets', $place + [
            'issues' => [['other_title' => 'Shelf collapsed', 'priority' => 'high', 'description' => 'The top shelf came down.']],
        ], $this->headers())->assertCreated()->json('data.id');

        return Ticket::query()->findOrFail($id);
    }

    private function storeless(Ticket $ticket, string $path = ''): string
    {
        $issue = $ticket->ticketIssues()->sole();

        return "/api/tickets/{$ticket->id}/issues/{$issue->id}".$path;
    }

    public function test_a_ticket_can_be_opened_for_a_storage_location(): void
    {
        $ticket = $this->open(['storage_location_id' => $this->storage->id]);

        $this->assertNull($ticket->store_id);
        $this->assertNull($ticket->other_store);
        $this->assertSame($this->storage->id, $ticket->storage_location_id);

        $this->getJson("/api/tickets/{$ticket->id}/issues", $this->headers())->assertOk()
            ->assertJsonPath('ticket.storage_location.name', 'Main storage')
            ->assertJsonPath('ticket.store_id', null);

        $this->getJson('/api/tickets?q=Main%20storage', $this->headers())->assertOk()
            ->assertJsonPath('data.0.id', $ticket->id);
    }

    public function test_a_ticket_is_at_storage_or_a_written_place_never_both_and_never_a_deleted_location(): void
    {
        $issues = ['issues' => [['other_title' => 'X', 'priority' => 'low', 'description' => 'Y']]];

        $this->postJson('/api/tickets', ['storage_location_id' => $this->storage->id, 'other_store' => 'Depot'] + $issues, $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['storage_location_id']);

        $this->postJson('/api/tickets', $issues, $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['other_store']);

        $this->storage->delete();
        $this->postJson('/api/tickets', ['storage_location_id' => $this->storage->id] + $issues, $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['storage_location_id']);
    }

    public function test_the_workflow_runs_on_the_storeless_routes(): void
    {
        $ticket = $this->open(['storage_location_id' => $this->storage->id]);

        $this->postJson($this->storeless($ticket, '/review'), [], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'in_review');

        $this->postJson($this->storeless($ticket, '/notes'), ['body' => 'Needs two people'], $this->headers())->assertSuccessful();

        $this->getJson($this->storeless($ticket), $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'in_review');
    }

    public function test_a_question_on_a_storage_ticket_tells_no_store_manager(): void
    {
        $ticket = $this->open(['storage_location_id' => $this->storage->id]);
        $ticket->ticketIssues()->sole()->forceFill(['status' => IssueStatus::InReview])->save();

        $this->postJson($this->storeless($ticket, '/info-requests'), ['question' => 'Which shelf?'], $this->headers())->assertOk();

        $this->assertCount(1, $this->outbox(IssueEventPublisher::INFO_REQUESTED));
        $this->assertCount(0, $this->outbox(IssueEventPublisher::NOTIFY_ROLE));
    }

    public function test_a_store_question_tells_that_stores_managers(): void
    {
        $this->putStatus(IssueStatus::InReview);
        $this->act('info-requests', ['question' => 'Is the pilot light on?'])->assertOk();

        $sent = $this->outbox(IssueEventPublisher::NOTIFY_ROLE);
        $this->assertCount(1, $sent);
        $this->assertSame(['Store Manager'], $sent[0]->payload['data']['roles']);
        $this->assertSame(['03795-00001'], $sent[0]->payload['data']['stores']);
        $this->assertSame('maintenance_question_for_store', $sent[0]->payload['data']['payload']['type']);
    }

    public function test_a_store_tickets_issue_is_not_reached_through_another_tickets_storeless_url(): void
    {
        $ticket = $this->open(['storage_location_id' => $this->storage->id]);

        // Scoped bindings: the issue must be the ticket's.
        $this->postJson("/api/tickets/{$ticket->id}/issues/{$this->issue->id}/review", [], $this->headers())->assertNotFound();
    }

    public function test_a_visit_for_a_storage_ticket_stops_at_that_storage_location(): void
    {
        $ticket = $this->open(['storage_location_id' => $this->storage->id]);
        $issue = $ticket->ticketIssues()->sole();
        $issue->forceFill(['status' => IssueStatus::InReview])->save();

        $this->postJson($this->storeless($ticket, '/schedule'), [
            'technician_id' => $this->technician->id,
            'date' => now()->addDay()->toDateString(),
        ], $this->headers())->assertOk();

        $stop = TripStop::query()->sole();
        $this->assertNull($stop->store_id);
        $this->assertNull($stop->other_store);
        $this->assertSame($this->storage->id, $stop->storage_location_id);
        $this->assertSame('Main storage', $stop->placeLabel());
    }
}
