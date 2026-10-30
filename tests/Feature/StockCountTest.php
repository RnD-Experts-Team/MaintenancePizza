<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Stock counts (docs/MAINTENANCE-V2.md §5): draft → counted → applied, over HTTP.
 */
class StockCountTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private StorageLocation $shelf;

    private Part $filter;

    private Part $belt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
        $this->shelf = StorageLocation::factory()->create(['name' => 'Main shelf']);
        $this->filter = Part::factory()->create(['name' => 'Air filter', 'unit' => 'each']);
        $this->belt = Part::factory()->create(['name' => 'Belt', 'unit' => 'each']);

        $this->buy($this->filter, '10', '5.00');
        $this->buy($this->belt, '4', '12.50');
    }

    private function buy(Part $part, string $quantity, string $cost): void
    {
        app(StockService::class)->record([
            'moved_at' => now()->subDay(),
            'type' => StockMovementType::Bought,
            'storage_location_id' => $this->shelf->id,
        ], [[
            'part_id' => $part->id,
            'storage_location_id' => $this->shelf->id,
            'quantity' => $quantity,
            'direction' => 1,
            'unit_cost' => $cost,
        ]]);
    }

    private function balance(Part $part): string
    {
        return number_format((float) StockBalance::query()->where('part_id', $part->id)->where('storage_location_id', $this->shelf->id)->value('quantity'), 2, '.', '');
    }

    public function test_draft_counted_applied_gives_the_right_balance_and_values(): void
    {
        $count = $this->postJson('/api/stock-counts', ['storage_location_id' => $this->shelf->id], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.part.name', 'Air filter')
            ->assertJsonPath('data.lines.0.expected', '10.00')
            ->assertJsonPath('data.lines.0.counted', null)
            ->assertJsonPath('data.lines.0.unit_cost', '5.0000')
            ->assertJsonPath('data.totals.total_lines', 2)
            ->assertJsonPath('data.totals.counted_lines', 0)
            ->json('data');

        $other = Part::factory()->create(['name' => 'Fuse']);

        $this->patchJson("/api/stock-counts/{$count['id']}", [
            'lines' => [
                ['part_id' => $this->filter->id, 'counted' => 7],
                ['part_id' => $this->belt->id, 'counted' => 5],
                ['part_id' => $other->id, 'counted' => null],
            ],
            'notes' => 'Friday count',
        ], $this->headers())
            ->assertOk()
            ->assertJsonCount(3, 'data.lines')
            ->assertJsonPath('data.notes_text', 'Friday count')
            ->assertJsonPath('data.lines.0.difference', '-3.00')
            ->assertJsonPath('data.lines.0.value_difference', '-15.00')
            ->assertJsonPath('data.totals.missing_quantity', '3.00')
            ->assertJsonPath('data.totals.missing_value', '15.00')
            ->assertJsonPath('data.totals.extra_quantity', '1.00')
            ->assertJsonPath('data.totals.extra_value', '12.50')
            ->assertJsonPath('data.totals.counted_lines', 2);

        $applied = $this->postJson("/api/stock-counts/{$count['id']}/apply", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('data.totals.missing_value', '15.00')
            ->assertJsonPath('data.totals.extra_value', '12.50')
            ->json('data');

        $this->assertSame('7.00', $this->balance($this->filter));
        $this->assertSame('5.00', $this->balance($this->belt));

        $movement = StockMovement::query()->where('stock_count_id', $count['id'])->sole();
        $this->assertSame(StockMovementType::StockCount, $movement->type);
        $this->assertCount(2, $movement->lines);
        $this->assertSame($movement->id, $applied['stock_movement_id']);

        // Values are frozen: buying more at another price changes nothing on the report.
        $this->buy($this->filter, '10', '9.00');
        $this->getJson("/api/stock-counts/{$count['id']}", $this->headers())
            ->assertJsonPath('data.lines.0.unit_cost', '5.0000')
            ->assertJsonPath('data.totals.missing_value', '15.00');

        // An applied count is the report: no edits, no apply, no delete.
        $this->patchJson("/api/stock-counts/{$count['id']}", ['lines' => [['part_id' => $this->filter->id, 'counted' => 1]]], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/stock-counts/{$count['id']}/apply", [], $this->headers())->assertUnprocessable();
        $this->deleteJson("/api/stock-counts/{$count['id']}", [], $this->headers())->assertUnprocessable();

        $this->getJson("/api/stock-counts?storage_location_id={$this->shelf->id}&status=applied", $this->headers())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lines', null)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_apply_recomputes_expected_from_the_balance_at_that_moment(): void
    {
        $count = $this->postJson('/api/stock-counts', ['storage_location_id' => $this->shelf->id], $this->headers())->json('data');

        $this->patchJson("/api/stock-counts/{$count['id']}", ['lines' => [['part_id' => $this->filter->id, 'counted' => 8]]], $this->headers())->assertOk();

        // Two filters are used between making the sheet and applying it.
        app(StockService::class)->record([
            'moved_at' => now(),
            'type' => StockMovementType::Used,
            'storage_location_id' => $this->shelf->id,
        ], [['part_id' => $this->filter->id, 'storage_location_id' => $this->shelf->id, 'quantity' => '2', 'direction' => -1]]);

        $this->postJson("/api/stock-counts/{$count['id']}/apply", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.lines.0.expected', '8.00')
            ->assertJsonPath('data.lines.0.difference', '0.00');

        // Counted 8, and 8 were there: nothing to write; the uncounted belt is left alone.
        $this->assertSame('8.00', $this->balance($this->filter));
        $this->assertSame('4.00', $this->balance($this->belt));
        $this->assertSame(0, StockMovement::query()->where('stock_count_id', $count['id'])->count());
    }

    public function test_nothing_counted_cannot_be_applied_and_a_draft_can_be_deleted(): void
    {
        $count = $this->postJson('/api/stock-counts', ['storage_location_id' => $this->shelf->id], $this->headers())->json('data');

        $this->postJson("/api/stock-counts/{$count['id']}/apply", [], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('lines');

        $this->deleteJson("/api/stock-counts/{$count['id']}", [], $this->headers())->assertNoContent();
        $this->getJson("/api/stock-counts/{$count['id']}", $this->headers())->assertNotFound();
    }

    public function test_a_count_for_one_place_lists_only_the_parts_there(): void
    {
        $level = $this->shelf->placeLevels()->create(['name' => 'Shelf', 'sort_order' => 1]);
        $a = $level->placeValues()->create(['value' => 'A', 'sort_order' => 1]);
        $balance = StockBalance::query()->where('part_id', $this->belt->id)->sole();
        app(StockService::class)->setBalancePlace($balance, [$a->id]);

        $this->postJson('/api/stock-counts', ['storage_location_id' => $this->shelf->id, 'storage_place_value_id' => $a->id], $this->headers())
            ->assertCreated()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.part.name', 'Belt')
            ->assertJsonPath('data.storage_place_value.value', 'A');

        $elsewhere = StorageLocation::factory()->create();
        $this->postJson('/api/stock-counts', ['storage_location_id' => $elsewhere->id, 'storage_place_value_id' => $a->id], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('storage_place_value_id');
    }
}
