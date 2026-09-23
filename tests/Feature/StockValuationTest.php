<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Part;
use App\Models\StockBalance;
use App\Models\StorageLocation;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the stock is worth, FIFO, derived from the ledger.
 *
 * Nothing is stored: the lots are walked from the movement lines on read. These
 * tests therefore assert BEHAVIOUR -- what a figure comes out as after a given
 * history -- rather than the shape of any intermediate bookkeeping, because
 * there is no intermediate bookkeeping to assert on.
 */
class StockValuationTest extends TestCase
{
    use RefreshDatabase;

    private Part $part;
    private StorageLocation $shelf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->part = Part::factory()->create();
        $this->shelf = StorageLocation::factory()->create();
    }

    private function buy(string $quantity, ?string $unitCost, string $at, ?StorageLocation $where = null)
    {
        return app(StockService::class)->record(
            ['moved_at' => $at, 'type' => StockMovementType::Purchase],
            [array_filter([
                'part_id' => $this->part->id,
                'storage_location_id' => ($where ?? $this->shelf)->id,
                'quantity' => $quantity,
                'direction' => 1,
                'unit_cost' => $unitCost,
            ], fn ($v) => $v !== null)]
        );
    }

    private function draw(string $quantity, string $at, ?StorageLocation $where = null)
    {
        return app(StockService::class)->record(
            ['moved_at' => $at, 'type' => StockMovementType::Draw],
            [[
                'part_id' => $this->part->id,
                'storage_location_id' => ($where ?? $this->shelf)->id,
                'quantity' => $quantity,
                'direction' => -1,
            ]]
        );
    }

    /** @return array<string, mixed> */
    private function value(): array
    {
        return app(StockService::class)->valuePartsFifo([$this->part->id])[$this->part->id];
    }

    /* ────────────────────────────────────────────────────────────── FIFO */

    public function test_it_values_what_is_left_at_what_it_cost(): void
    {
        $this->buy('10', '5.0000', '2026-01-01 09:00:00');

        $this->assertSame('10.00', $this->value()['quantity']);
        $this->assertSame('50.00', $this->value()['value']);
    }

    /** Oldest first: the cheap lot goes before the dear one. */
    public function test_an_issue_takes_the_oldest_lot_first(): void
    {
        $this->buy('10', '5.0000', '2026-01-01 09:00:00');
        $this->buy('10', '9.0000', '2026-02-01 09:00:00');

        $this->draw('10', '2026-03-01 09:00:00');

        // The @5 lot is gone entirely; the @9 lot is untouched.
        $this->assertSame('10.00', $this->value()['quantity']);
        $this->assertSame('90.00', $this->value()['value']);
    }

    public function test_an_issue_spanning_two_lots_leaves_the_remainder_of_the_dearer_one(): void
    {
        $this->buy('10', '5.0000', '2026-01-01 09:00:00');
        $this->buy('10', '9.0000', '2026-02-01 09:00:00');

        $this->draw('15', '2026-03-01 09:00:00');

        // 5 of the @9 lot left.
        $this->assertSame('5.00', $this->value()['quantity']);
        $this->assertSame('45.00', $this->value()['value']);
    }

    /** The bcmath case: thirds do not survive a float round-trip. */
    public function test_fractional_quantities_do_not_drift(): void
    {
        $this->buy('0.30', '3.0000', '2026-01-01 09:00:00');
        $this->buy('0.30', '3.0000', '2026-01-02 09:00:00');
        $this->buy('0.30', '3.0000', '2026-01-03 09:00:00');

        $this->draw('0.90', '2026-02-01 09:00:00');

        $this->assertSame('0.00', $this->value()['quantity']);
        $this->assertSame('0.00', $this->value()['value']);
    }

    /* ─────────────────────────────────────────────────────── Unknown cost */

    /**
     * We never invent a price. Stock that arrived with none contributes zero to
     * the value and is reported separately, so an incomplete figure is visibly
     * incomplete rather than quietly wrong.
     */
    public function test_stock_with_no_recorded_price_is_worth_nothing_and_says_so(): void
    {
        app(StockService::class)->record(
            ['moved_at' => '2026-01-01 09:00:00', 'type' => StockMovementType::InitialCount],
            [[
                'part_id' => $this->part->id,
                'storage_location_id' => $this->shelf->id,
                'quantity' => '7',
                'direction' => 1,
            ]]
        );

        $value = $this->value();
        $this->assertSame('7.00', $value['quantity']);
        $this->assertSame('0.00', $value['value']);
        $this->assertSame('7.00', $value['unknown_quantity']);
    }

    /** A later unpriced receipt borrows the last price we actually saw. */
    public function test_a_later_unpriced_receipt_assumes_the_last_known_price(): void
    {
        $this->buy('5', '6.0000', '2026-01-01 09:00:00');
        $this->buy('5', null, '2026-02-01 09:00:00');

        $value = $this->value();
        $this->assertSame('60.00', $value['value']);
        // Assumed, not unknown -- we have a basis for it.
        $this->assertSame('0.00', $value['unknown_quantity']);
    }

    /* ─────────────────────────────────────────────────────────── Transfers */

    /** The acid test: walking parts across the room does not make us richer. */
    public function test_a_transfer_does_not_change_what_the_stock_is_worth(): void
    {
        $van = StorageLocation::factory()->create();
        $this->buy('10', '7.5000', '2026-01-01 09:00:00');

        $before = $this->value()['value'];

        app(StockService::class)->record(
            ['moved_at' => '2026-05-01 09:00:00', 'type' => StockMovementType::TransferOut],
            [
                ['part_id' => $this->part->id, 'storage_location_id' => $this->shelf->id, 'quantity' => '4', 'direction' => -1],
                ['part_id' => $this->part->id, 'storage_location_id' => $van->id, 'quantity' => '4', 'direction' => 1],
            ]
        );

        $after = $this->value();
        $this->assertSame($before, $after['value']);
        // And it landed at the price it left at, not at nothing.
        $this->assertSame('30.00', $after['locations'][$van->id]['value']);
    }

    /**
     * A receipt in a LATER movement must not inherit the cost of an earlier
     * issue -- the pool only carries within one movement. Otherwise new stock
     * gets priced at whatever old stock happened to leave at.
     */
    public function test_a_later_receipt_does_not_inherit_from_an_earlier_issue(): void
    {
        $this->buy('5', '5.0000', '2026-01-01 09:00:00');
        $this->draw('5', '2026-02-01 09:00:00');
        $this->buy('5', '11.0000', '2026-03-01 09:00:00');

        $this->assertSame('55.00', $this->value()['value']);
    }

    /* ─────────────────────────────────────────────────────────── Reversals */

    public function test_reversing_an_issue_restores_what_it_was_worth(): void
    {
        $this->buy('10', '5.0000', '2026-01-01 09:00:00');
        $draw = $this->draw('4', '2026-02-01 09:00:00');

        $this->assertSame('30.00', $this->value()['value']);

        app(StockService::class)->reverse($draw);

        $this->assertSame('50.00', $this->value()['value']);
    }

    public function test_reversing_a_purchase_removes_what_it_added(): void
    {
        $this->buy('10', '5.0000', '2026-01-01 09:00:00');
        $dear = $this->buy('10', '9.0000', '2026-02-01 09:00:00');

        app(StockService::class)->reverse($dear);

        // The cheap lot survives; only the reversed purchase's value goes.
        $this->assertSame('50.00', $this->value()['value']);
    }

    /**
     * Value tracks quantity below zero rather than being clamped. A negative is
     * the trace of a reversal applied after the stock had already gone, and it
     * is a real signal stock:reconcile reports.
     */
    public function test_value_follows_quantity_negative(): void
    {
        $return = app(StockService::class)->record(
            ['moved_at' => '2026-01-01 09:00:00', 'type' => StockMovementType::Return],
            [[
                'part_id' => $this->part->id,
                'storage_location_id' => $this->shelf->id,
                'quantity' => '5',
                'direction' => 1,
                'unit_cost' => '5.0000',
            ]]
        );
        $this->draw('5', '2026-02-01 09:00:00');

        app(StockService::class)->reverse($return);

        $this->assertSame('-5.00', StockBalance::first()->quantity);
        $this->assertSame('-5.00', $this->value()['quantity']);
        $this->assertSame('-25.00', $this->value()['value']);
    }

    /**
     * The approved behaviour change, kept from the earlier work: pressing
     * "mistaken" twice returns the reversal that exists rather than writing a
     * second one and taking the balance past zero the other way.
     */
    public function test_reversing_twice_returns_the_existing_reversal(): void
    {
        $this->buy('10', '5.0000', '2026-01-01 09:00:00');
        $draw = $this->draw('4', '2026-02-01 09:00:00');

        $first = app(StockService::class)->reverse($draw);
        $second = app(StockService::class)->reverse($draw);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('10.00', StockBalance::first()->quantity);
    }

    /* ──────────────────────────────────────────────── The documented cost */

    /**
     * The price of deriving on read instead of freezing a second ledger: a
     * BACKDATED receipt changes what earlier issues are deemed to have cost,
     * because the walk is redone from scratch. Textbook FIFO restates for
     * exactly this reason, and the rule is simple to state -- the figures always
     * reflect the ledger as it stands now.
     *
     * Pinned as a test so the behaviour is a decision on the record rather than
     * something discovered later.
     */
    public function test_a_backdated_receipt_restates_what_earlier_issues_cost(): void
    {
        $this->buy('5', '9.0000', '2026-02-01 09:00:00');
        $this->draw('2', '2026-02-02 09:00:00');

        // Three of the @9 lot left.
        $this->assertSame('27.00', $this->value()['value']);

        // A receipt dated BEFORE that issue now exists.
        $this->buy('5', '1.0000', '2026-01-01 09:00:00');

        // The issue is re-costed against the older, cheaper lot: 3 @1 + 5 @9.
        $this->assertSame('8.00', $this->value()['quantity']);
        $this->assertSame('48.00', $this->value()['value']);
    }

    /** Value is reported per location as well as per part. */
    public function test_it_reports_value_per_location(): void
    {
        $van = StorageLocation::factory()->create();
        $this->buy('4', '5.0000', '2026-01-01 09:00:00');
        $this->buy('2', '10.0000', '2026-01-02 09:00:00', $van);

        $value = $this->value();
        $this->assertSame('40.00', $value['value']);
        $this->assertSame('20.00', $value['locations'][$this->shelf->id]['value']);
        $this->assertSame('20.00', $value['locations'][$van->id]['value']);
    }
}
