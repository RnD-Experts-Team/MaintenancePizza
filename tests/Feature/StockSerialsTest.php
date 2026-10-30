<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartUnit;
use App\Models\StockBalance;
use App\Models\StorageLocation;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * POST stock-movements with serial-tracked parts and a ticket issue
 * (docs/MAINTENANCE-V2.md §5), and the part units it moves.
 */
class StockSerialsTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Part $part;

    private StorageLocation $shelf;

    private StorageLocation $van;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
        $this->part = Part::factory()->create(['name' => 'Compressor', 'track_serials' => true]);
        $this->shelf = StorageLocation::factory()->create(['name' => 'Main shelf']);
        $this->van = StorageLocation::factory()->create(['name' => 'Van']);
    }

    /** @param  array<int, string>  $serials */
    private function opening(array $serials): void
    {
        $this->postJson('/api/stock-movements', [
            'moved_at' => '2026-10-01 09:00:00',
            'type' => 'opening_stock',
            'lines' => [[
                'part_id' => $this->part->id,
                'storage_location_id' => $this->shelf->id,
                'quantity' => count($serials),
                'unit_cost' => '100',
                'serials' => $serials,
            ]],
        ], $this->headers())->assertCreated();
    }

    private function balance(StorageLocation $location): string
    {
        return number_format((float) StockBalance::query()
            ->where('part_id', $this->part->id)
            ->where('storage_location_id', $location->id)
            ->value('quantity'), 2, '.', '');
    }

    public function test_opening_stock_creates_units_in_stock(): void
    {
        $this->opening(['A1', 'A2']);

        $this->assertSame('2.00', $this->balance($this->shelf));
        $this->getJson("/api/parts/{$this->part->id}/units?status=in_stock", $this->headers())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.storage_location.name', 'Main shelf');
    }

    public function test_a_tracked_part_needs_one_serial_per_piece(): void
    {
        $this->postJson('/api/stock-movements', [
            'moved_at' => '2026-10-01',
            'type' => 'opening_stock',
            'lines' => [['part_id' => $this->part->id, 'storage_location_id' => $this->shelf->id, 'quantity' => 2, 'serials' => ['A1']]],
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('lines.0.serials');

        $plain = Part::factory()->create();
        $this->postJson('/api/stock-movements', [
            'moved_at' => '2026-10-01',
            'type' => 'opening_stock',
            'lines' => [['part_id' => $plain->id, 'storage_location_id' => $this->shelf->id, 'quantity' => 1, 'serials' => ['X']]],
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('lines.0.serials');
    }

    public function test_used_on_a_ticket_issue_installs_the_units_and_mistaken_puts_them_back(): void
    {
        $this->opening(['A1', 'A2']);
        $store = Store::factory()->create(['store_number' => '03795-00009']);
        $ticket = Ticket::factory()->create(['store_id' => $store->id]);
        $issue = TicketIssue::factory()->create(['ticket_id' => $ticket->id, 'other_title' => 'Walk-in cooler']);

        $movement = $this->postJson('/api/stock-movements', [
            'moved_at' => '2026-10-02 10:00:00',
            'type' => 'used',
            'ticket_issue_id' => $issue->id,
            'lines' => [[
                'part_id' => $this->part->id,
                'storage_location_id' => $this->shelf->id,
                'quantity' => 1,
                'serials' => ['A2'],
            ]],
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'Used')
            ->assertJsonPath('data.ticket_issue.id', $issue->id)
            ->assertJsonPath('data.ticket_issue.store_number', '03795-00009')
            ->assertJsonPath('data.ticket_issue.title', 'Walk-in cooler')
            ->assertJsonPath('data.lines.0.serials', ['A2'])
            ->json('data');

        $this->assertSame('1.00', $this->balance($this->shelf));
        $unit = PartUnit::query()->where('serial_number', 'A2')->sole();
        $this->assertSame('installed', $unit->status);
        $this->assertSame($issue->id, $unit->ticket_issue_id);
        $this->assertNull($unit->storage_location_id);

        $this->getJson("/api/parts/{$this->part->id}/units?status=installed", $this->headers())
            ->assertJsonPath('data.0.ticket_issue.ticket_id', $ticket->id);

        // A unit that is not in stock there cannot be used again.
        $this->postJson('/api/stock-movements', [
            'moved_at' => '2026-10-02',
            'type' => 'used',
            'lines' => [['part_id' => $this->part->id, 'storage_location_id' => $this->shelf->id, 'quantity' => 1, 'serials' => ['A2']]],
        ], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors', fn ($e) => str_contains(json_encode($e), 'Serial A2 of Compressor is not in stock at Main shelf.'));
        $this->assertSame('1.00', $this->balance($this->shelf));

        $this->postJson("/api/stock-movements/{$movement['id']}/mistaken", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('reversal.type_label', 'Undone')
            ->assertJsonPath('reversal.lines.0.serials', ['A2']);

        $this->assertSame('2.00', $this->balance($this->shelf));
        $unit->refresh();
        $this->assertSame('in_stock', $unit->status);
        $this->assertSame($this->shelf->id, $unit->storage_location_id);
        $this->assertNull($unit->ticket_issue_id);
    }

    public function test_a_ticket_issue_only_goes_on_a_used_movement(): void
    {
        $issue = TicketIssue::factory()->create();

        $this->postJson('/api/stock-movements', [
            'moved_at' => '2026-10-01',
            'type' => 'opening_stock',
            'ticket_issue_id' => $issue->id,
            'lines' => [['part_id' => $this->part->id, 'storage_location_id' => $this->shelf->id, 'quantity' => 1, 'serials' => ['Z']]],
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('ticket_issue_id');

        $this->postJson('/api/stock-movements', [
            'moved_at' => '2026-10-01',
            'type' => 'bought',
            'lines' => [['part_id' => $this->part->id, 'storage_location_id' => $this->shelf->id, 'quantity' => 1, 'serials' => ['Z']]],
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_moving_units_between_locations_and_writing_them_off(): void
    {
        $this->opening(['A1', 'A2']);

        $this->postJson('/api/stock-movements', [
            'moved_at' => '2026-10-03',
            'type' => 'moved',
            'lines' => [
                ['part_id' => $this->part->id, 'storage_location_id' => $this->van->id, 'quantity' => 1, 'direction' => 1, 'serials' => ['A1']],
                ['part_id' => $this->part->id, 'storage_location_id' => $this->shelf->id, 'quantity' => 1, 'direction' => -1, 'serials' => ['A1']],
            ],
        ], $this->headers())->assertCreated();

        $this->assertSame($this->van->id, PartUnit::query()->where('serial_number', 'A1')->value('storage_location_id'));
        $this->assertSame('1.00', $this->balance($this->van));

        $this->postJson('/api/stock-movements', [
            'moved_at' => '2026-10-04',
            'type' => 'damaged_lost',
            'lines' => [['part_id' => $this->part->id, 'storage_location_id' => $this->van->id, 'quantity' => 1, 'serials' => ['A1']]],
        ], $this->headers())->assertCreated()->assertJsonPath('data.type_label', 'Damaged or lost');

        $this->assertSame('damaged_lost', PartUnit::query()->where('serial_number', 'A1')->value('status'));
        $this->assertSame('0.00', $this->balance($this->van));
    }

    public function test_a_unit_serial_and_warranty_can_be_corrected(): void
    {
        $this->opening(['A1', 'A2']);
        $unit = PartUnit::query()->where('serial_number', 'A1')->sole();

        $this->patchJson("/api/part-units/{$unit->id}", ['serial_number' => 'A1-FIX', 'warranty_until' => '2027-10-01'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.serial_number', 'A1-FIX')
            ->assertJsonPath('data.warranty_until', '2027-10-01');

        $this->patchJson("/api/part-units/{$unit->id}", ['serial_number' => 'A2'], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.serial_number.0', 'Another unit of this part already has that serial number.');
    }
}
