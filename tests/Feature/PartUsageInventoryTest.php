<?php

namespace Tests\Feature;

use App\Enums\PartUsagePayer;
use App\Enums\PartUsageSource;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Part;
use App\Models\PartUsage;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\Technician;
use App\Models\TicketIssue;
use App\Services\StockService;
use App\Services\WorkflowRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartUsageInventoryTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowRecordService $workflow;

    private StockService $stock;

    private Part $part;

    private StorageLocation $shelf;

    private TicketIssue $issue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workflow = app(WorkflowRecordService::class);
        $this->stock = app(StockService::class);
        $this->part = Part::factory()->create();
        $this->shelf = StorageLocation::factory()->create();
        $this->issue = TicketIssue::factory()->create();
    }

    private function stockUp(string $quantity): void
    {
        $this->stock->record([
            'moved_at' => now(),
            'type' => StockMovementType::Purchase,
            'storage_location_id' => $this->shelf->id,
        ], [[
            'part_id' => $this->part->id,
            'storage_location_id' => $this->shelf->id,
            'quantity' => $quantity,
            'direction' => 1,
            'unit_cost' => '5.00',
        ]]);
    }

    private function onHand(?StorageLocation $location = null): string
    {
        return (string) (StockBalance::where('part_id', $this->part->id)
            ->where('storage_location_id', ($location ?? $this->shelf)->id)
            ->value('quantity') ?? '0.00');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function create(array $overrides = []): array
    {
        return $this->workflow->createPartUsage(array_replace([
            'ticket_issue_ids' => [$this->issue->id],
            'part_id' => $this->part->id,
            'quantity' => '3',
            'unit_cost' => '12.50',
            'source' => PartUsageSource::Purchased->value,
            'paid_by' => PartUsagePayer::Us->value,
        ], $overrides), []);
    }

    public function test_cost_is_computed_as_quantity_times_unit_cost(): void
    {
        $result = $this->create(['quantity' => '3', 'unit_cost' => '12.50']);

        $this->assertSame('37.50', (string) $result['cost']);
        $this->assertSame('3.00', (string) $result['quantity']);
        $this->assertSame('12.5000', (string) $result['unit_cost']);
    }

    public function test_drawing_from_storage_writes_an_outbound_movement_and_lowers_stock(): void
    {
        $this->stockUp('10');

        $result = $this->create([
            'quantity' => '4',
            'source' => PartUsageSource::FromStorage->value,
            'storage_location_id' => $this->shelf->id,
        ]);

        $this->assertSame('6.00', $this->onHand());

        $movement = StockMovement::where('part_usage_id', $result['id'])->sole();
        $this->assertSame(StockMovementType::Draw, $movement->type);
        $this->assertSame(-1, $movement->lines()->sole()->direction);
    }

    /**
     * The draw and the part usage share one transaction, so a draw that cannot
     * be satisfied must leave no trace of either.
     */
    public function test_drawing_more_than_is_on_hand_creates_no_part_usage_at_all(): void
    {
        $this->stockUp('2');

        $this->expectException(InsufficientStockException::class);

        try {
            $this->create([
                'quantity' => '9',
                'source' => PartUsageSource::FromStorage->value,
                'storage_location_id' => $this->shelf->id,
            ]);
        } finally {
            $this->assertSame(0, PartUsage::count());
            $this->assertSame('2.00', $this->onHand());
            $this->assertSame(0, StockMovement::where('type', StockMovementType::Draw->value)->count());
        }
    }

    /**
     * Bought ten, used six, six go on the job and four go on the shelf. Those
     * four are entering inventory for the first time, so only the inbound
     * movement is written — there was no draw to pair it with.
     */
    public function test_a_purchased_usage_with_a_return_only_writes_the_inbound_movement(): void
    {
        $result = $this->create([
            'quantity' => '10',
            'unit_cost' => '5.00',
            'returned_quantity' => '4',
            'returned_to_storage_location_id' => $this->shelf->id,
        ]);

        $this->assertSame('4.00', $this->onHand());

        $movements = StockMovement::where('part_usage_id', $result['id'])->get();
        $this->assertCount(1, $movements);
        $this->assertSame(StockMovementType::Return, $movements->first()->type);

        // Gross stays 50 (what was paid); net is 30 (what was consumed).
        $this->assertSame('50.00', (string) $result['cost']);
        $this->assertSame('30.00', $result['net_cost']);
        $this->assertSame('6.00', $result['net_quantity']);
    }

    /** A storage draw that is partly handed back writes both legs. */
    public function test_a_storage_usage_with_a_return_nets_out_across_two_movements(): void
    {
        $this->stockUp('10');

        $result = $this->create([
            'quantity' => '6',
            'source' => PartUsageSource::FromStorage->value,
            'storage_location_id' => $this->shelf->id,
            'returned_quantity' => '2',
            'returned_to_storage_location_id' => $this->shelf->id,
        ]);

        // 10 - 6 + 2
        $this->assertSame('6.00', $this->onHand());
        $this->assertCount(2, StockMovement::where('part_usage_id', $result['id'])->get());
    }

    public function test_unused_parts_can_go_back_to_a_different_location(): void
    {
        $other = StorageLocation::factory()->create();
        $this->stockUp('10');

        $this->create([
            'quantity' => '6',
            'source' => PartUsageSource::FromStorage->value,
            'storage_location_id' => $this->shelf->id,
            'returned_quantity' => '2',
            'returned_to_storage_location_id' => $other->id,
        ]);

        $this->assertSame('4.00', $this->onHand());
        $this->assertSame('2.00', $this->onHand($other));
    }

    public function test_marking_a_storage_usage_mistaken_puts_the_stock_back(): void
    {
        $this->stockUp('10');

        $result = $this->create([
            'quantity' => '4',
            'source' => PartUsageSource::FromStorage->value,
            'storage_location_id' => $this->shelf->id,
        ]);

        $this->assertSame('6.00', $this->onHand());

        $this->workflow->markPartUsageMistaken(PartUsage::findOrFail($result['id']));

        $this->assertSame('10.00', $this->onHand());
        $this->assertTrue(PartUsage::findOrFail($result['id'])->mistaken);

        // Corrected by an added reversal, not by removing the draw.
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Draw->value)->count());
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Reversal->value)->count());
    }

    public function test_marking_a_purchased_usage_mistaken_writes_nothing_to_the_ledger(): void
    {
        $result = $this->create();

        $this->workflow->markPartUsageMistaken(PartUsage::findOrFail($result['id']));

        $this->assertSame(0, StockMovement::count());
    }

    public function test_marking_mistaken_twice_does_not_double_reverse(): void
    {
        $this->stockUp('10');
        $result = $this->create([
            'quantity' => '4',
            'source' => PartUsageSource::FromStorage->value,
            'storage_location_id' => $this->shelf->id,
        ]);

        $usage = PartUsage::findOrFail($result['id']);
        $this->workflow->markPartUsageMistaken($usage);
        $this->workflow->markPartUsageMistaken($usage->fresh());

        $this->assertSame('10.00', $this->onHand());
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Reversal->value)->count());
    }

    public function test_a_technician_paid_usage_is_flagged_reimbursable(): void
    {
        $technician = Technician::factory()->create();

        $ours = $this->create();
        $theirs = $this->create([
            'paid_by' => PartUsagePayer::Technician->value,
            'paid_by_technician_id' => $technician->id,
        ]);

        $this->assertFalse($ours['reimbursable']);
        $this->assertTrue($theirs['reimbursable']);
        $this->assertSame($technician->id, $theirs['paid_by_technician_id']);
    }

    /**
     * The legacy `{part_id, cost}` payload still means one at that price.
     */
    public function test_the_legacy_payload_shape_is_normalised_by_the_request(): void
    {
        $request = new \App\Http\Requests\StorePartUsageRequest();
        $request->merge([
            'ticket_issue_ids' => [$this->issue->id],
            'part_id' => $this->part->id,
            'cost' => '42.00',
        ]);

        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);

        $this->assertSame(1, $request->input('quantity'));
        $this->assertSame('42.00', $request->input('unit_cost'));
        $this->assertSame('purchased', $request->input('source'));
        $this->assertSame('us', $request->input('paid_by'));
    }
}
