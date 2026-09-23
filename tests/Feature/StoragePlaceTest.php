<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StoragePlaceLevel;
use App\Models\StoragePlaceValue;
use App\Models\StorageLocation;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where inside a location a part actually sits.
 *
 * EVERY TEST HERE GOES OVER HTTP, DELIBERATELY. The feature this replaces was
 * tested by calling StorageLocationService directly, and so its tests passed
 * green while PATCH and DELETE returned 500 for every user: the route parameter
 * was {storageSlot}, Laravel's scoped binding resolved that to a
 * storageSlots() relation via Str::plural(Str::camel(...)), and the model
 * defined slots(). A service-level test cannot see a route-binding bug.
 *
 * So the first two tests below are not about places at all. They are about the
 * wiring, and they are the tests that would have caught it.
 *
 * Auth is bypassed because the middleware verifies against an external auth
 * server; what is under test is binding and controller wiring.
 */
class StoragePlaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\AuthTokenStoreScopeMiddleware::class);
    }

    private function location(string $name = 'Storage A'): StorageLocation
    {
        return StorageLocation::factory()->create(['name' => $name]);
    }

    private function level(StorageLocation $location, string $name, int $order = 0): StoragePlaceLevel
    {
        return StoragePlaceLevel::query()->create([
            'storage_location_id' => $location->id,
            'name' => $name,
            'sort_order' => $order,
        ]);
    }

    private function value(StoragePlaceLevel $level, string $value): StoragePlaceValue
    {
        return StoragePlaceValue::query()->create([
            'storage_place_level_id' => $level->id,
            'value' => $value,
        ]);
    }

    /** A real balance, made the way the ledger makes one. */
    private function balance(Part $part, StorageLocation $location, string $quantity = '12'): StockBalance
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

        return StockBalance::query()
            ->where('part_id', $part->id)
            ->where('storage_location_id', $location->id)
            ->firstOrFail();
    }

    /* ------------------------------------------------- the wiring itself */

    public function test_a_nested_level_can_be_patched_over_http(): void
    {
        $location = $this->location();
        $level = $this->level($location, 'Shelf');

        $this->patchJson("/api/storage-locations/{$location->id}/place-levels/{$level->id}", [
            'name' => 'Shelving',
        ])->assertOk()->assertJsonPath('data.name', 'Shelving');
    }

    public function test_a_nested_level_can_be_deleted_over_http(): void
    {
        $location = $this->location();
        $level = $this->level($location, 'Shelf');

        $this->deleteJson("/api/storage-locations/{$location->id}/place-levels/{$level->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('storage_place_levels', ['id' => $level->id]);
    }

    public function test_a_value_can_be_created_updated_and_deleted_over_http(): void
    {
        $location = $this->location();
        $level = $this->level($location, 'Shelf');

        $created = $this->postJson(
            "/api/storage-locations/{$location->id}/place-levels/{$level->id}/values",
            ['value' => 'C']
        )->assertCreated()->json('data');

        $this->patchJson(
            "/api/storage-locations/{$location->id}/place-levels/{$level->id}/values/{$created['id']}",
            ['value' => 'C1']
        )->assertOk()->assertJsonPath('data.value', 'C1');

        $this->deleteJson(
            "/api/storage-locations/{$location->id}/place-levels/{$level->id}/values/{$created['id']}"
        )->assertNoContent();

        $this->assertSoftDeleted('storage_place_values', ['id' => $created['id']]);
    }

    /** scopeBindings is not decoration: one location's level must not be
     *  reachable through another location's URL. */
    public function test_a_level_cannot_be_reached_through_another_locations_url(): void
    {
        $mine = $this->location('Storage A');
        $other = $this->location('Storage B');
        $level = $this->level($mine, 'Shelf');

        $this->patchJson("/api/storage-locations/{$other->id}/place-levels/{$level->id}", [
            'name' => 'Hijacked',
        ])->assertNotFound();

        $this->assertSame('Shelf', $level->fresh()->name);
    }

    /* --------------------------------------------------------- the levels */

    public function test_a_location_lists_its_levels_with_their_values(): void
    {
        $location = $this->location();
        $shelf = $this->level($location, 'Shelf', 0);
        $row = $this->level($location, 'Row', 1);
        $this->value($shelf, 'C');
        $this->value($row, '8');

        $data = $this->getJson("/api/storage-locations/{$location->id}/place-levels")
            ->assertOk()->json('data');

        // Declared order, not alphabetical: Shelf then Row, because that is the
        // order the address is read in.
        $this->assertSame(['Shelf', 'Row'], array_column($data, 'name'));
        $this->assertSame(['C'], array_column($data[0]['values'], 'value'));
    }

    public function test_two_locations_may_each_have_a_level_called_shelf(): void
    {
        $a = $this->location('Storage A');
        $b = $this->location('Storage B');
        $this->level($a, 'Shelf');

        $this->postJson("/api/storage-locations/{$b->id}/place-levels", ['name' => 'Shelf'])
            ->assertCreated();
    }

    public function test_one_location_cannot_have_the_same_level_twice(): void
    {
        $location = $this->location();
        $this->level($location, 'Shelf');

        $this->postJson("/api/storage-locations/{$location->id}/place-levels", ['name' => 'Shelf'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    /* -------------------------------------------------------- the address */

    public function test_a_part_can_be_given_an_address(): void
    {
        $location = $this->location();
        $part = Part::factory()->create();
        $balance = $this->balance($part, $location);

        $shelf = $this->level($location, 'Shelf', 0);
        $row = $this->level($location, 'Row', 1);
        $c = $this->value($shelf, 'C');
        $eight = $this->value($row, '8');

        $data = $this->putJson("/api/stock-balances/{$balance->id}/place", [
            // Deliberately out of order: the response must come back in the
            // location's declared level order regardless.
            'place_value_ids' => [$eight->id, $c->id],
        ])->assertOk()->json('data');

        $this->assertSame(['Shelf', 'Row'], array_column($data, 'level'));
        $this->assertSame(['C', '8'], array_column($data, 'value'));
    }

    public function test_every_level_is_optional(): void
    {
        $location = $this->location();
        $part = Part::factory()->create();
        $balance = $this->balance($part, $location);

        $this->level($location, 'Shelf', 0);
        $column = $this->level($location, 'Column', 2);
        $five = $this->value($column, '5');

        // A thing that lives in a column and nothing else says exactly that.
        $data = $this->putJson("/api/stock-balances/{$balance->id}/place", [
            'place_value_ids' => [$five->id],
        ])->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Column', $data[0]['level']);
    }

    /** PUT replaces the whole address, so a level left out is cleared. */
    public function test_setting_the_address_again_replaces_it_rather_than_merging(): void
    {
        $location = $this->location();
        $part = Part::factory()->create();
        $balance = $this->balance($part, $location);

        $shelf = $this->level($location, 'Shelf', 0);
        $row = $this->level($location, 'Row', 1);
        $c = $this->value($shelf, 'C');
        $eight = $this->value($row, '8');

        $this->putJson("/api/stock-balances/{$balance->id}/place", [
            'place_value_ids' => [$c->id, $eight->id],
        ])->assertOk();

        $data = $this->putJson("/api/stock-balances/{$balance->id}/place", [
            'place_value_ids' => [$c->id],
        ])->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Shelf', $data[0]['level']);
    }

    public function test_an_empty_list_clears_the_address(): void
    {
        $location = $this->location();
        $part = Part::factory()->create();
        $balance = $this->balance($part, $location);
        $shelf = $this->level($location, 'Shelf');
        $c = $this->value($shelf, 'C');

        $this->putJson("/api/stock-balances/{$balance->id}/place", ['place_value_ids' => [$c->id]])
            ->assertOk();

        $this->putJson("/api/stock-balances/{$balance->id}/place", ['place_value_ids' => []])
            ->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('stock_balance_places', 0);
    }

    /** Otherwise a part in Storage A could be recorded as sitting on Storage
     *  B's shelf, which reads as a fact and is not one. */
    public function test_a_value_from_another_location_is_refused(): void
    {
        $mine = $this->location('Storage A');
        $other = $this->location('Storage B');
        $part = Part::factory()->create();
        $balance = $this->balance($part, $mine);

        $elsewhere = $this->value($this->level($other, 'Shelf'), 'C');

        $this->putJson("/api/stock-balances/{$balance->id}/place", [
            'place_value_ids' => [$elsewhere->id],
        ])->assertStatus(422)->assertJsonValidationErrors('place_value_ids');
    }

    public function test_two_values_on_one_level_are_refused(): void
    {
        $location = $this->location();
        $part = Part::factory()->create();
        $balance = $this->balance($part, $location);

        $shelf = $this->level($location, 'Shelf');
        $c = $this->value($shelf, 'C');
        $d = $this->value($shelf, 'D');

        $this->putJson("/api/stock-balances/{$balance->id}/place", [
            'place_value_ids' => [$c->id, $d->id],
        ])->assertStatus(422)->assertJsonValidationErrors('place_value_ids');
    }

    /* ------------------------------------------------------- retiring bits */

    /**
     * Retiring a value takes the addresses that used it with it.
     *
     * The slots feature left them behind: the FK was nullOnDelete but the
     * delete was SOFT, so the column kept pointing at a retired row while the
     * API rendered null -- two stories about one fact.
     */
    public function test_retiring_a_value_clears_the_addresses_that_used_it(): void
    {
        $location = $this->location();
        $part = Part::factory()->create();
        $balance = $this->balance($part, $location);

        $shelf = $this->level($location, 'Shelf', 0);
        $row = $this->level($location, 'Row', 1);
        $c = $this->value($shelf, 'C');
        $eight = $this->value($row, '8');

        $this->putJson("/api/stock-balances/{$balance->id}/place", [
            'place_value_ids' => [$c->id, $eight->id],
        ])->assertOk();

        $this->deleteJson(
            "/api/storage-locations/{$location->id}/place-levels/{$shelf->id}/values/{$c->id}"
        )->assertNoContent();

        // The other level survives, and the quantity is untouched.
        $this->assertDatabaseCount('stock_balance_places', 1);
        $this->assertDatabaseHas('stock_balance_places', ['storage_place_value_id' => $eight->id]);
        $this->assertSame('12.00', $balance->fresh()->quantity);
    }

    public function test_retiring_a_level_takes_its_values_and_addresses_with_it(): void
    {
        $location = $this->location();
        $part = Part::factory()->create();
        $balance = $this->balance($part, $location);

        $shelf = $this->level($location, 'Shelf');
        $c = $this->value($shelf, 'C');

        $this->putJson("/api/stock-balances/{$balance->id}/place", ['place_value_ids' => [$c->id]])
            ->assertOk();

        $this->deleteJson("/api/storage-locations/{$location->id}/place-levels/{$shelf->id}")
            ->assertNoContent();

        $this->assertDatabaseCount('stock_balance_places', 0);
        $this->assertSoftDeleted('storage_place_values', ['id' => $c->id]);
        $this->assertSame('12.00', $balance->fresh()->quantity);
    }

    /* ----------------------------------------------------- the read paths */

    public function test_the_address_comes_back_on_the_balances_listing(): void
    {
        $location = $this->location();
        $part = Part::factory()->create();
        $balance = $this->balance($part, $location);

        $shelf = $this->level($location, 'Shelf');
        $c = $this->value($shelf, 'C');

        $this->putJson("/api/stock-balances/{$balance->id}/place", ['place_value_ids' => [$c->id]])
            ->assertOk();

        $rows = $this->getJson('/api/stock-balances')->assertOk()->json('data');

        $this->assertSame('Shelf', $rows[0]['place'][0]['level']);
        $this->assertSame('C', $rows[0]['place'][0]['value']);
    }

    /** An untagged part reports an EMPTY address, not a missing key -- "nobody
     *  has said" is a real answer and the client has to be able to say it. */
    public function test_an_untagged_part_reports_an_empty_address(): void
    {
        $location = $this->location();
        $part = Part::factory()->create();
        $this->balance($part, $location);

        $rows = $this->getJson('/api/stock-balances')->assertOk()->json('data');

        $this->assertSame([], $rows[0]['place']);
    }

    /**
     * The address survives a reconcile.
     *
     * stock_balances is a cache the reconcile command rebuilds. The address now
     * lives on its own table rather than as a column on that cache, which is
     * what makes this safe by construction rather than by remembering to keep
     * updateOrCreate's second argument narrow.
     */
    public function test_an_address_survives_a_reconcile(): void
    {
        $location = $this->location();
        $part = Part::factory()->create();
        $balance = $this->balance($part, $location);

        $shelf = $this->level($location, 'Shelf');
        $c = $this->value($shelf, 'C');

        $this->putJson("/api/stock-balances/{$balance->id}/place", ['place_value_ids' => [$c->id]])
            ->assertOk();

        $balance->forceFill(['quantity' => '999.00'])->save();

        $this->artisan('stock:reconcile', ['--fix' => true])->assertExitCode(0);

        $this->assertSame('12.00', $balance->fresh()->quantity);
        $this->assertDatabaseHas('stock_balance_places', [
            'stock_balance_id' => $balance->id,
            'storage_place_value_id' => $c->id,
        ]);
    }
}
