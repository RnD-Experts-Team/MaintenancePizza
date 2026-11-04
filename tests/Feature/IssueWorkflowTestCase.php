<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\MaintenanceOutboxEvent;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Shared ground for the issue workflow tests (docs/MAINTENANCE.md §2): a
 * store, a ticket with one issue, a technician, and helpers to drive the
 * workflow endpoints over HTTP through the real auth middleware.
 */
abstract class IssueWorkflowTestCase extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    protected Store $store;

    protected Ticket $ticket;

    protected TicketIssue $issue;

    protected Technician $technician;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        $this->fakeAuthServer();

        $this->store = Store::factory()->create(['store_number' => '03795-00001']);
        $this->ticket = Ticket::factory()->for($this->store)->create();
        $this->issue = TicketIssue::factory()->for($this->ticket)->create(['other_title' => 'Walk-in cooler']);
        $this->technician = Technician::factory()->create(['name' => 'Sam Fixer', 'phone' => '614-555-0100']);
    }

    protected function url(string $path = '', ?TicketIssue $issue = null): string
    {
        $issue ??= $this->issue;

        return "/api/stores/{$this->store->store_number}/tickets/{$issue->ticket_id}/issues/{$issue->id}".$path;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function act(string $path, array $body = [], ?TicketIssue $issue = null): TestResponse
    {
        return $this->postJson($this->url('/'.ltrim($path, '/'), $issue), $body, $this->headers());
    }

    protected function read(?TicketIssue $issue = null): TestResponse
    {
        return $this->getJson($this->url('', $issue), $this->headers())->assertOk();
    }

    protected function putStatus(IssueStatus $status, ?TicketIssue $issue = null): TicketIssue
    {
        $issue ??= $this->issue;
        $issue->forceFill(['status' => $status])->save();

        return $issue;
    }

    protected function assertStatus(IssueStatus $status, ?TicketIssue $issue = null): void
    {
        $this->assertSame($status, ($issue ?? $this->issue)->fresh()->status);
    }

    protected function assertRefused(TestResponse $response, string $field = 'status'): TestResponse
    {
        return $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    }

    /**
     * @return array<int, MaintenanceOutboxEvent>
     */
    protected function outbox(string $subject): array
    {
        return MaintenanceOutboxEvent::query()->where('subject', $subject)->orderBy('id')->get()->all();
    }
}
