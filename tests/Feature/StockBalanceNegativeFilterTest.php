<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Part;
use App\Models\StorageLocation;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ?negative_only=1 on the balances listing.
 *
 * It exists because the dashboard used to count shortages by loading the first
 * page of balances and filtering it in the browser: the KPI said "3 negative"
 * while the list it opened said "none here", because the negatives were on page
 * four. A count that is only true for the first page is not a count.
 *
 * EVERY TEST HERE GOES OVER HTTP, per the note in routes/api.php: a filter that
 * the controller forgets to whitelist is invisible to a service-level test, and
 * that is precisely the bug that would ship.
 */
class StockBalanceNegativeFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\AuthTokenStoreScopeMiddleware::class);
    }

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

    private function draw(Part $part, StorageLocation $location, string $quantity): void
    {
        app(StockService::class)->record(
            [
                'moved_at' => '2026-01-02 09:00:00',
                'type' => StockMovementType::Draw,
                'storage_location_id' => $location->id,
            ],
            [[
                'part_id' => $part->id,
                'storage_location_id' => $location->id,
                'quantity' => $quantity,
                'direction' => -1,
            ]]
        );
    }

    /**
     * Drive a pair below zero. An ordinary Draw cannot: the service refuses to
     * overdraw. Only a correction may, which is exactly how shortages appear in
     * real data -- somebody reverses a receipt whose stock has since been used.
     */
    private function short(Part $part, StorageLocation $location, string $quantity = '3'): void
    {
        app(StockService::class)->record(
            ['moved_at' => '2026-01-03 09:00:00', 'type' => StockMovementType::Adjustment],
            [[
                'part_id' => $part->id,
                'storage_location_id' => $location->id,
                'quantity' => $quantity,
                'direction' => -1,
            ]],
            allowNegative: true
        );
    }

    public function test_it_returns_only_the_pairs_that_are_below_zero(): void
    {
        $healthy = Part::factory()->create();
        $shortage = Part::factory()->create();
        $location = StorageLocation::factory()->create();

        $this->receive($healthy, $location, '10');
        $this->short($shortage, $location);

        $body = $this->getJson('/api/stock-balances?negative_only=1')
            ->assertOk()
            ->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame($shortage->id, $body['data'][0]['part_id']);
    }

    /**
     * The whole point: the count must be the total across every page, not the
     * size of the page that happened to load.
     */
    public function test_the_total_counts_every_negative_not_just_the_first_page(): void
    {
        $location = StorageLocation::factory()->create();

        foreach (range(1, 5) as $ignored) {
            $this->short(Part::factory()->create(), $location);
        }
        $this->receive(Part::factory()->create(), $location, '10');

        $body = $this->getJson('/api/stock-balances?negative_only=1&per_page=2')
            ->assertOk()
            ->json();

        $this->assertCount(2, $body['data']);
        $this->assertSame(5, $body['meta']['total'] ?? $body['total']);
    }

    /**
     * non_zero hides what netted to zero; negative_only keeps what is below it.
     * Sending both must not be how a shortage disappears.
     */
    public function test_it_is_independent_of_non_zero(): void
    {
        $location = StorageLocation::factory()->create();
        $shortage = Part::factory()->create();
        $this->short($shortage, $location);

        // A pair that netted back to nothing: hidden by non_zero, and not
        // negative either, so it must be absent from both readings.
        $settled = Part::factory()->create();
        $this->receive($settled, $location, '4');
        $this->draw($settled, $location, '4');

        $body = $this->getJson('/api/stock-balances?negative_only=1&non_zero=1')
            ->assertOk()
            ->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame($shortage->id, $body['data'][0]['part_id']);
    }

    /**
     * Grouped, the filter applies to the TOTAL: a part that is short on one
     * shelf and long on another owes nothing overall.
     */
    public function test_grouped_by_part_it_applies_to_the_total(): void
    {
        $evensOut = Part::factory()->create();
        $shelfA = StorageLocation::factory()->create();
        $shelfB = StorageLocation::factory()->create();
        $this->short($evensOut, $shelfA, '5');
        $this->receive($evensOut, $shelfB, '5');

        $trulyShort = Part::factory()->create();
        $this->short($trulyShort, $shelfA);

        $body = $this->getJson('/api/stock-balances?group_by=part&negative_only=1')
            ->assertOk()
            ->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame($trulyShort->id, $body['data'][0]['part_id']);
    }

    /** Without the flag, nothing changes: the default listing is unfiltered. */
    public function test_omitting_the_flag_returns_everything(): void
    {
        $location = StorageLocation::factory()->create();
        $this->receive(Part::factory()->create(), $location, '10');
        $this->short(Part::factory()->create(), $location);

        $body = $this->getJson('/api/stock-balances')->assertOk()->json();

        $this->assertCount(2, $body['data']);
    }
}
