<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StorageLocation;
use App\Models\StorageSlot;
use App\Services\StockService;
use App\Services\StorageLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Named places inside a storage location.
 *
 * A location answers "Storage A"; a slot answers "shelf C, section 5, column Z"
 * -- the thing you need in order to walk over and pick the part up.
 *
 * The slot is NOT a stock dimension. Quantities stay per (part, location) and
 * no movement names a slot. The tests below pin that, because the tempting
 * mistake is to start splitting counts by shelf.
 */
class StorageSlotTest extends TestCase
{
    use RefreshDatabase;

    private StorageLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->location = StorageLocation::factory()->create(['name' => 'Storage A']);
    }

    private function service(): StorageLocationService
    {
        return app(StorageLocationService::class);
    }

    public function test_a_location_defines_its_own_slots(): void
    {
        $this->service()->createSlot($this->location, ['name' => 'Shelf C']);
        $this->service()->createSlot($this->location, ['name' => 'Shelf D']);

        $other = StorageLocation::factory()->create();
        $this->service()->createSlot($other, ['name' => 'Front rack']);

        $names = array_column($this->service()->slots($this->location), 'name');

        $this->assertSame(['Shelf C', 'Shelf D'], $names);
        $this->assertSame(['Front rack'], array_column($this->service()->slots($other), 'name'));
    }

    /** Two locations may each have a "Shelf A". That is not a clash. */
    public function test_the_same_slot_name_may_exist_in_two_locations(): void
    {
        $other = StorageLocation::factory()->create();

        $this->service()->createSlot($this->location, ['name' => 'Shelf A']);
        $this->service()->createSlot($other, ['name' => 'Shelf A']);

        $this->assertSame(2, StorageSlot::count());
    }

    /** Hand-ordered, so slots list in walking order rather than alphabetically. */
    public function test_slots_come_back_in_the_order_they_were_given(): void
    {
        $this->service()->createSlot($this->location, ['name' => 'Zebra bay', 'sort_order' => 1]);
        $this->service()->createSlot($this->location, ['name' => 'Alpha bay', 'sort_order' => 2]);

        $this->assertSame(
            ['Zebra bay', 'Alpha bay'],
            array_column($this->service()->slots($this->location), 'name')
        );
    }

    /** Unlike locations, slots are editable -- a mislabelled shelf is not worth
     *  retiring and recreating. */
    public function test_a_slot_can_be_renamed(): void
    {
        $created = $this->service()->createSlot($this->location, ['name' => 'Shelf C']);
        $slot = StorageSlot::findOrFail($created['id']);

        $updated = $this->service()->updateSlot($slot, ['name' => 'Shelf C (top)']);

        $this->assertSame('Shelf C (top)', $updated['name']);
    }

    /**
     * Retiring a slot must not strand the stock. The FK nulls rather than
     * restricting: the part stays exactly where it is, we simply stop claiming
     * to know which shelf.
     */
    public function test_retiring_a_slot_leaves_the_stock_alone(): void
    {
        $part = Part::factory()->create();
        $created = $this->service()->createSlot($this->location, ['name' => 'Shelf C']);
        $slot = StorageSlot::findOrFail($created['id']);

        $this->receive($part, '5');
        $balance = StockBalance::firstOrFail();
        $balance->update(['storage_slot_id' => $slot->id]);

        $this->service()->deleteSlot($slot);

        $this->assertSame('5.00', $balance->fresh()->quantity, 'The stock is untouched.');
        $this->assertSoftDeleted('storage_slots', ['id' => $slot->id]);
    }

    /** The slot records where a part LIVES. It does not split the count. */
    public function test_a_slot_does_not_split_the_stock_count(): void
    {
        $part = Part::factory()->create();
        $created = $this->service()->createSlot($this->location, ['name' => 'Shelf C']);

        $this->receive($part, '5');
        StockBalance::firstOrFail()->update(['storage_slot_id' => $created['id']]);
        $this->receive($part, '3');

        $this->assertSame(1, StockBalance::count(), 'Still one row per (part, location).');
        $this->assertSame('8.00', StockBalance::firstOrFail()->quantity);
        $this->assertSame('8.00', $part->onHand());
    }

    /**
     * THE CAVEAT, pinned. `storage_slot_id` is the one user-entered column on
     * what is otherwise a cache of the ledger. `stock:reconcile --fix` rewrites
     * that cache, and it must not take the slot with it.
     */
    public function test_a_slot_survives_a_reconcile(): void
    {
        $part = Part::factory()->create();
        $created = $this->service()->createSlot($this->location, ['name' => 'Shelf C']);

        $this->receive($part, '5');
        $balance = StockBalance::firstOrFail();
        $balance->update(['storage_slot_id' => $created['id']]);

        // Drift the cache so --fix has something to repair.
        $balance->update(['quantity' => '999.00']);

        $this->artisan('stock:reconcile', ['--fix' => true])->assertSuccessful();

        $fresh = StockBalance::firstOrFail();
        $this->assertSame('5.00', $fresh->quantity, 'The quantity was repaired from the ledger.');
        $this->assertSame($created['id'], $fresh->storage_slot_id, 'And the slot was not lost with it.');
    }

    private function receive(Part $part, string $quantity): void
    {
        app(StockService::class)->record(
            ['moved_at' => '2026-01-01 09:00:00', 'type' => StockMovementType::Purchase],
            [[
                'part_id' => $part->id,
                'storage_location_id' => $this->location->id,
                'quantity' => $quantity,
                'direction' => 1,
                'unit_cost' => '5.0000',
            ]]
        );
    }
}
