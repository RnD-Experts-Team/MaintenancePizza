<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Part;
use App\Models\StorageLocation;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ?group_by=part on the balances listing.
 *
 * The ungrouped listing is one row per (part, location), which is honest but
 * means a part on four shelves appears four times with no total anywhere. The
 * total could not be computed on the client either, because the endpoint
 * paginates -- summing a page is not summing a part.
 */
class StockBalanceByPartTest extends TestCase
{
    use RefreshDatabase;

    private function receive(Part $part, StorageLocation $location, string $quantity): void
    {
        app(StockService::class)->record(
            [
                'moved_at' => '2026-01-01 09:00:00',
                'type' => StockMovementType::Purchase,
                'storage_location_id' => $location->id,
            ],
            [[
                'part_id' => $part->id,
                'storage_location_id' => $location->id,
                'quantity' => $quantity,
                'direction' => 1,
                'unit_cost' => '5.0000',
            ]]
        );
    }

    /** @return array<string, mixed> */
    private function firstRow(array $filters = []): array
    {
        $page = app(StockService::class)->listBalancesByPart($filters);

        return (array) $page->items()[0];
    }

    public function test_it_totals_one_part_across_every_location(): void
    {
        $part = Part::factory()->create();
        $warehouse = StorageLocation::factory()->create();
        $van = StorageLocation::factory()->create();

        $this->receive($part, $warehouse, '8');
        $this->receive($part, $van, '6');

        $row = $this->firstRow();

        $this->assertSame($part->id, $row['part_id']);
        $this->assertSame('14.00', $row['quantity']);
        $this->assertSame(2, $row['location_count']);
    }

    /**
     * The acid test. Part::onHand() has computed this figure since the
     * beginning and was never serialized; if the two ever disagree, one of them
     * is lying to somebody.
     */
    public function test_the_total_is_identical_to_part_on_hand(): void
    {
        $part = Part::factory()->create();

        $this->receive($part, StorageLocation::factory()->create(), '3.5');
        $this->receive($part, StorageLocation::factory()->create(), '0.25');
        $this->receive($part, StorageLocation::factory()->create(), '11');

        $this->assertSame($part->onHand(), $this->firstRow()['quantity']);
    }

    public function test_it_carries_the_per_location_breakdown(): void
    {
        $part = Part::factory()->create();
        $warehouse = StorageLocation::factory()->create(['name' => 'Warehouse']);
        $van = StorageLocation::factory()->create(['name' => 'Van 1']);

        $this->receive($part, $warehouse, '8');
        $this->receive($part, $van, '6');

        $locations = $this->firstRow()['locations'];

        $this->assertCount(2, $locations);
        $names = array_map(fn ($l) => $l['storage_location']['name'], $locations);
        $this->assertContains('Warehouse', $names);
        $this->assertContains('Van 1', $names);
    }

    /**
     * non_zero applies to the TOTAL here, not to each pair: a part that is +5 on
     * one shelf and -5 on another has nothing, and should hide exactly as a
     * single zeroed pair does in the ungrouped listing.
     */
    public function test_non_zero_hides_a_part_whose_locations_cancel_out(): void
    {
        $part = Part::factory()->create();
        $a = StorageLocation::factory()->create();
        $b = StorageLocation::factory()->create();

        $this->receive($part, $a, '5');

        // Drive b negative by the same amount, which only a reversal may do.
        app(StockService::class)->record(
            ['moved_at' => '2026-01-02 09:00:00', 'type' => StockMovementType::Adjustment],
            [['part_id' => $part->id, 'storage_location_id' => $b->id, 'quantity' => '5', 'direction' => -1]],
            allowNegative: true
        );

        $this->assertSame('0.00', $part->onHand());
        $this->assertCount(0, app(StockService::class)->listBalancesByPart(['non_zero' => 1])->items());
        $this->assertCount(1, app(StockService::class)->listBalancesByPart([])->items());
    }

    public function test_a_part_that_never_moved_does_not_appear(): void
    {
        Part::factory()->create();

        $this->assertCount(0, app(StockService::class)->listBalancesByPart([])->items());
    }
}
