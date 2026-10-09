<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\IssueStatusChange;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * "I opened a ticket about the Oven for a store -- show me that store's last
 * Oven tickets." One row per earlier ticket, newest first.
 */
class IssueHistoryTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $store;

    private Issue $oven;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
        $this->store = Store::factory()->create(['store_number' => '03795-00001']);
        $this->oven = Issue::factory()->create(['title' => 'Oven']);
    }

    private function url(array $query = []): string
    {
        return "/api/stores/{$this->store->store_number}/issues/{$this->oven->id}/history"
            . ($query ? '?' . http_build_query($query) : '');
    }

    private function ovenTicket(?Store $store = null, string $createdAt = '2026-09-01 10:00:00', IssueStatus $status = IssueStatus::Pending): Ticket
    {
        $ticket = Ticket::factory()->for($store ?? $this->store)->create(['created_at' => $createdAt]);
        TicketIssue::factory()->for($ticket)->create([
            'issue_id' => $this->oven->id,
            'other_title' => null,
            'status' => $status->value,
            'created_at' => $createdAt,
        ]);

        return $ticket;
    }

    public function test_lists_this_stores_tickets_for_the_issue_newest_first(): void
    {
        $older = $this->ovenTicket(createdAt: '2026-08-01 09:00:00');
        $newer = $this->ovenTicket(createdAt: '2026-09-15 09:00:00');
        $this->ovenTicket(Store::factory()->create()); // another store
        $sink = Ticket::factory()->for($this->store)->create();
        TicketIssue::factory()->for($sink)->create(['issue_id' => Issue::factory()->create()->id]);

        $this->getJson($this->url(), $this->headers())
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.ticket_id', $newer->id)
            ->assertJsonPath('data.1.ticket_id', $older->id)
            ->assertJsonPath('data.0.store_number', '03795-00001');
    }

    public function test_the_ticket_being_viewed_is_left_out(): void
    {
        $current = $this->ovenTicket(createdAt: '2026-10-01 09:00:00');
        $earlier = $this->ovenTicket(createdAt: '2026-09-01 09:00:00');

        $this->getJson($this->url(['exclude_ticket' => $current->id]), $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ticket_id', $earlier->id);
    }

    public function test_a_deferral_chain_is_one_row_reporting_where_it_stands_now(): void
    {
        $ticket = Ticket::factory()->for($this->store)->create();
        $root = TicketIssue::factory()->for($ticket)->create([
            'issue_id' => $this->oven->id, 'status' => IssueStatus::Deferred->value,
        ]);
        $child = TicketIssue::factory()->for($ticket)->create([
            'issue_id' => $this->oven->id, 'status' => IssueStatus::Complete->value, 'parent_id' => $root->id,
        ]);
        IssueStatusChange::query()->create([
            'ticket_issue_id' => $child->id, 'from_status' => 'in_progress', 'to_status' => 'complete',
        ])->forceFill(['created_at' => '2026-09-20 16:30:00'])->save();

        $technician = Technician::factory()->create(['name' => 'Ahmed']);
        $root->technicians()->attach($technician->id);
        $child->technicians()->attach($technician->id);

        $this->getJson($this->url(), $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(2, 'data.0.issues')
            ->assertJsonPath('data.0.status.value', 'complete')
            ->assertJsonPath('data.0.completed_at', '2026-09-20T16:30:00.000000Z')
            ->assertJsonPath('data.0.technicians', [['id' => $technician->id, 'name' => 'Ahmed']]);
    }

    public function test_open_only_keeps_tickets_where_the_issue_is_still_open(): void
    {
        $open = $this->ovenTicket(status: IssueStatus::Assigned);
        $this->ovenTicket(status: IssueStatus::Complete);
        $this->ovenTicket(status: IssueStatus::Cancelled);

        $this->getJson($this->url(['open_only' => 1]), $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ticket_id', $open->id);
    }

    public function test_archived_tickets_are_left_out(): void
    {
        $this->ovenTicket()->delete();

        $this->getJson($this->url(), $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_paginates(): void
    {
        foreach (range(1, 12) as $day) {
            $this->ovenTicket(createdAt: sprintf('2026-09-%02d 09:00:00', $day));
        }

        $this->getJson($this->url(['per_page' => 10]), $this->headers())
            ->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('last_page', 2);
        $this->getJson($this->url(['per_page' => 10, 'page' => 2]), $this->headers())
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_history_stays_readable_after_the_issue_leaves_the_catalog(): void
    {
        $this->ovenTicket();
        $this->oven->delete();

        $this->getJson($this->url(), $this->headers())->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_description_is_trimmed_for_the_list(): void
    {
        $ticket = Ticket::factory()->for($this->store)->create();
        TicketIssue::factory()->for($ticket)->create([
            'issue_id' => $this->oven->id,
            'description' => str_repeat('The pilot light keeps going out. ', 20),
        ]);

        $description = $this->getJson($this->url(), $this->headers())->json('data.0.issues.0.description');

        $this->assertLessThanOrEqual(163, mb_strlen($description));
        $this->assertStringEndsWith('...', $description);
    }
}
