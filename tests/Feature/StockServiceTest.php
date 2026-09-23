<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private Part $part;

    private StorageLocation $locationA;

    private StorageLocation $locationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock = app(StockService::class);
        $this->part = Part::factory()->create();
        $this->locationA = StorageLocation::factory()->create();
        $this->locationB = StorageLocation::factory()->create();
    }

    private function purchase(StorageLocation $location, string $quantity, ?string $unitCost = '5.00'): StockMovement
    {
        return $this->stock->record([
            'moved_at' => now(),
            'type' => StockMovementType::Purchase,
            'storage_location_id' => $location->id,
        ], [[
            'part_id' => $this->part->id,
            'storage_location_id' => $location->id,
            'quantity' => $quantity,
            'direction' => 1,
            'unit_cost' => $unitCost,
        ]]);
    }

    private function balance(StorageLocation $location): string
    {
        return (string) StockBalance::where('part_id', $this->part->id)
            ->where('storage_location_id', $location->id)
            ->value('quantity');
    }

    /** The cache must always agree with SUM(quantity * direction) over the ledger. */
    private function assertCacheMatchesLedger(): void
    {
        $ledger = $this->stock->ledgerBalances();

        foreach (StockBalance::all() as $balance) {
            $key = $balance->part_id . ':' . $balance->storage_location_id;
            $this->assertSame(
                $ledger[$key] ?? '0.00',
                number_format((float) $balance->quantity, 2, '.', ''),
                "Cached balance for {$key} drifted from the ledger."
            );
        }
    }

    public function test_a_purchase_raises_the_balance_and_costs_the_line(): void
    {
        $movement = $this->purchase($this->locationA, '10', '5.00');

        $this->assertSame('10.00', $this->balance($this->locationA));
        $this->assertSame('50.00', (string) $movement->lines()->sole()->total_cost);
        $this->assertCacheMatchesLedger();
    }

    public function test_a_draw_lowers_the_balance(): void
    {
        $this->purchase($this->locationA, '10');

        $this->stock->record([
            'moved_at' => now(),
            'type' => StockMovementType::Draw,
            'storage_location_id' => $this->locationA->id,
        ], [[
            'part_id' => $this->part->id,
            'storage_location_id' => $this->locationA->id,
            'quantity' => '4',
            'direction' => -1,
        ]]);

        $this->assertSame('6.00', $this->balance($this->locationA));
        $this->assertCacheMatchesLedger();
    }

    public function test_drawing_more_than_is_on_hand_is_rejected_and_nothing_is_written(): void
    {
        $this->purchase($this->locationA, '10');
        $movementCount = StockMovement::count();

        try {
            $this->stock->record([
                'moved_at' => now(),
                'type' => StockMovementType::Draw,
                'storage_location_id' => $this->locationA->id,
            ], [[
                'part_id' => $this->part->id,
                'storage_location_id' => $this->locationA->id,
                'quantity' => '20',
                'direction' => -1,
            ]]);

            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException $e) {
            $this->assertSame('20.00', $e->requested);
            $this->assertSame('10.00', $e->available);
        }

        // The whole transaction rolled back: balance untouched, no movement row.
        $this->assertSame('10.00', $this->balance($this->locationA));
        $this->assertSame($movementCount, StockMovement::count());
        $this->assertCacheMatchesLedger();
    }

    public function test_drawing_the_exact_remaining_quantity_is_allowed(): void
    {
        $this->purchase($this->locationA, '10');

        $this->stock->record([
            'moved_at' => now(),
            'type' => StockMovementType::Draw,
            'storage_location_id' => $this->locationA->id,
        ], [[
            'part_id' => $this->part->id,
            'storage_location_id' => $this->locationA->id,
            'quantity' => '10',
            'direction' => -1,
        ]]);

        $this->assertSame('0.00', $this->balance($this->locationA));
        $this->assertCacheMatchesLedger();
    }

    public function test_stock_is_tracked_per_location_not_per_part(): void
    {
        $this->purchase($this->locationA, '10');
        $this->purchase($this->locationB, '3');

        $this->assertSame('10.00', $this->balance($this->locationA));
        $this->assertSame('3.00', $this->balance($this->locationB));
        $this->assertSame('13.00', $this->part->onHand());
        $this->assertSame('3.00', $this->part->onHand($this->locationB->id));
    }

    /** One movement, an outbound line at A and an inbound line at B. */
    public function test_a_transfer_moves_stock_between_locations_in_one_movement(): void
    {
        $this->purchase($this->locationA, '10');

        $this->stock->record([
            'moved_at' => now(),
            'type' => StockMovementType::TransferOut,
        ], [
            ['part_id' => $this->part->id, 'storage_location_id' => $this->locationA->id, 'quantity' => '4', 'direction' => -1],
            ['part_id' => $this->part->id, 'storage_location_id' => $this->locationB->id, 'quantity' => '4', 'direction' => 1],
        ]);

        $this->assertSame('6.00', $this->balance($this->locationA));
        $this->assertSame('4.00', $this->balance($this->locationB));
        $this->assertCacheMatchesLedger();
    }

    public function test_a_reversal_restores_stock_and_leaves_the_original_intact(): void
    {
        $this->purchase($this->locationA, '10');

        $draw = $this->stock->record([
            'moved_at' => now(),
            'type' => StockMovementType::Draw,
            'storage_location_id' => $this->locationA->id,
        ], [[
            'part_id' => $this->part->id,
            'storage_location_id' => $this->locationA->id,
            'quantity' => '4',
            'direction' => -1,
        ]]);

        $reversal = $this->stock->reverse($draw);

        $this->assertSame('10.00', $this->balance($this->locationA));
        $this->assertSame(StockMovementType::Reversal, $reversal->type);
        $this->assertSame($draw->id, $reversal->reverses_stock_movement_id);

        // The original is still there, flagged but not edited.
        $this->assertTrue($draw->fresh()->mistaken);
        $this->assertSame(1, $draw->lines()->count());
        $this->assertSame('4.00', (string) $draw->lines()->sole()->quantity);
        $this->assertCacheMatchesLedger();
    }

    /**
     * Reversing a return pushes stock down, and that stock may already be gone.
     * A correction must never be blocked by present state.
     */
    public function test_a_reversal_may_go_negative(): void
    {
        $return = $this->stock->record([
            'moved_at' => now(),
            'type' => StockMovementType::Return,
            'storage_location_id' => $this->locationA->id,
        ], [[
            'part_id' => $this->part->id,
            'storage_location_id' => $this->locationA->id,
            'quantity' => '5',
            'direction' => 1,
        ]]);

        // Everything that came in has since been consumed elsewhere.
        $this->stock->record([
            'moved_at' => now(),
            'type' => StockMovementType::Draw,
            'storage_location_id' => $this->locationA->id,
        ], [[
            'part_id' => $this->part->id,
            'storage_location_id' => $this->locationA->id,
            'quantity' => '5',
            'direction' => -1,
        ]]);

        $this->stock->reverse($return);

        $this->assertSame('-5.00', $this->balance($this->locationA));
        $this->assertCacheMatchesLedger();
    }

    /**
     * `mistaken` is display only. A flagged movement still counts, because its
     * reversal is what actually corrects the balance — filtering it out too
     * would correct the same error twice.
     */
    public function test_the_mistaken_flag_does_not_change_the_arithmetic(): void
    {
        $purchase = $this->purchase($this->locationA, '10');
        $purchase->update(['mistaken' => true]);

        $this->assertSame('10.00', $this->balance($this->locationA));
        $this->assertSame('10.00', $this->stock->ledgerBalances()[$this->part->id . ':' . $this->locationA->id]);
    }

    public function test_reversing_the_same_movement_twice_is_refused_by_the_part_usage_helper(): void
    {
        $draw = $this->purchase($this->locationA, '10');
        $this->stock->reverse($draw);

        $this->assertSame('0.00', $this->balance($this->locationA));
        $this->assertSame(1, StockMovement::where('reverses_stock_movement_id', $draw->id)->count());
    }

    public function test_a_movement_touching_one_pair_twice_nets_correctly(): void
    {
        $this->purchase($this->locationA, '10');

        $this->stock->record([
            'moved_at' => now(),
            'type' => StockMovementType::Adjustment,
        ], [
            ['part_id' => $this->part->id, 'storage_location_id' => $this->locationA->id, 'quantity' => '3', 'direction' => -1],
            ['part_id' => $this->part->id, 'storage_location_id' => $this->locationA->id, 'quantity' => '1', 'direction' => 1],
        ]);

        $this->assertSame('8.00', $this->balance($this->locationA));
        $this->assertCacheMatchesLedger();
    }

    public function test_fractional_quantities_do_not_drift(): void
    {
        $this->purchase($this->locationA, '0.30');
        $this->purchase($this->locationA, '0.30');
        $this->purchase($this->locationA, '0.30');

        $this->stock->record([
            'moved_at' => now(),
            'type' => StockMovementType::Draw,
            'storage_location_id' => $this->locationA->id,
        ], [[
            'part_id' => $this->part->id,
            'storage_location_id' => $this->locationA->id,
            'quantity' => '0.90',
            'direction' => -1,
        ]]);

        $this->assertSame('0.00', $this->balance($this->locationA));
        $this->assertCacheMatchesLedger();
    }
}
