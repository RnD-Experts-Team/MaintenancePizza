<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\MaintenanceOutboxEvent;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * tickets:send-update-notifications -- each ticket's Store Managers get one
 * notification of what changed since they were last told.
 */
class TicketUpdateNotificationsTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $store;

    private Ticket $ticket;

    private TicketIssue $oven;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        $this->fakeAuthServer();
        $this->store = Store::factory()->create(['store_number' => '03795-00001']);
        $issue = Issue::factory()->create(['title' => 'Oven']);

        $id = $this->postJson("/api/stores/{$this->store->store_number}/tickets", [
            'issues' => [['issue_id' => $issue->id, 'priority' => 'high', 'description' => 'Cold']],
        ], $this->headers())->assertCreated()->json('data.id');

        $this->ticket = Ticket::query()->findOrFail($id);
        $this->oven = $this->ticket->ticketIssues()->sole();
        $this->travel(1)->minutes();
    }

    /** @return array<int, MaintenanceOutboxEvent> */
    private function updates(): array
    {
        return MaintenanceOutboxEvent::query()
            ->get()
            ->filter(fn (MaintenanceOutboxEvent $row) => ($row->payload['data']['payload']['type'] ?? null) === 'maintenance_ticket_updated')
            ->values()
            ->all();
    }

    private function setStatus(string $status): void
    {
        $this->postJson("/api/stores/{$this->store->store_number}/tickets/{$this->ticket->id}/issues/status", [
            'ticket_issue_ids' => [$this->oven->id],
            'status' => $status,
        ], $this->headers())->assertSuccessful();
    }

    public function test_opening_a_ticket_is_not_an_update(): void
    {
        $this->artisan('tickets:send-update-notifications')->assertSuccessful();

        $this->assertSame([], $this->updates());
    }

    public function test_changes_go_out_once_grouped_to_the_store_managers(): void
    {
        $this->setStatus('assigned');
        $this->travel(1)->minutes();
        $this->setStatus('in_progress');
        $this->postJson("/api/stores/{$this->store->store_number}/tickets/{$this->ticket->id}/notes", [
            'body' => 'Tech is on the way',
            'files' => [UploadedFile::fake()->create('quote.pdf', 10)],
        ], $this->headers())->assertCreated();
        $this->travel(1)->minutes();

        $this->artisan('tickets:send-update-notifications')->assertSuccessful();

        $updates = $this->updates();
        $this->assertCount(1, $updates);
        $data = $updates[0]->payload['data'];
        $this->assertSame('notifications.v1.notification.role.send', $updates[0]->subject);
        $this->assertSame(['Store Manager'], $data['roles']);
        $this->assertSame(['03795-00001'], $data['stores']);
        $this->assertSame("Ticket #{$this->ticket->id} for Store 03795-00001 was updated", $data['payload']['title']);
        $this->assertSame('Oven: Pending → In Progress · 1 new note, 1 new file', $data['payload']['body']);
        $this->assertSame("/dashboard/maintenance-tickets/{$this->ticket->id}?store=03795-00001", $data['payload']['action_url']);

        // Told: nothing goes out again until something else changes.
        $this->travel(1)->minutes();
        $this->artisan('tickets:send-update-notifications')->assertSuccessful();
        $this->assertCount(1, $this->updates());

        $this->setStatus('complete');
        $this->travel(1)->minutes();
        $this->artisan('tickets:send-update-notifications')->assertSuccessful();

        $updates = $this->updates();
        $this->assertCount(2, $updates);
        $this->assertSame('Oven: In Progress → Complete', $updates[1]->payload['data']['payload']['body']);
    }

    public function test_a_private_note_alone_tells_nobody(): void
    {
        $this->postJson("/api/stores/{$this->store->store_number}/tickets/{$this->ticket->id}/notes", [
            'body' => 'Vendor is slow, chase on Friday',
            'is_private' => true,
        ], $this->headers())->assertCreated();
        $this->travel(1)->minutes();

        $this->artisan('tickets:send-update-notifications')->assertSuccessful();

        $this->assertSame([], $this->updates());
        $this->assertTrue($this->ticket->fresh()->last_notified_at->greaterThan($this->ticket->updated_at));
    }

    public function test_a_change_to_a_record_counts_as_a_change_to_its_ticket(): void
    {
        $this->postJson("/api/stores/{$this->store->store_number}/tickets/{$this->ticket->id}/issues/{$this->oven->id}/notes", [
            'body' => 'Pilot light keeps going out',
        ], $this->headers())->assertCreated();
        $this->travel(1)->minutes();

        $this->artisan('tickets:send-update-notifications')->assertSuccessful();

        $this->assertSame('1 new note', $this->updates()[0]->payload['data']['payload']['body']);
    }
}
