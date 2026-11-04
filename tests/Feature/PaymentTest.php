<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\IssueInspection;
use App\Models\Part;
use App\Models\PartUnit;
use App\Models\Payment;
use App\Models\PaymentRevision;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\TechnicianPurchase;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripEvent;
use App\Models\TripSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Payments and unpaid work (docs/MAINTENANCE.md §4), over HTTP.
 */
class PaymentTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    private Technician $tom;

    private Trip $trip;

    /** 08:00–11:00 with a 30-minute break: 150 paid minutes. */
    private TripSession $morning;

    /** 13:00–14:00: 60 paid minutes. */
    private TripSession $afternoon;

    private StorageLocation $shelf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();

        $this->store = Store::factory()->create(['store_number' => '03795-00001']);
        $this->otherStore = Store::factory()->create(['store_number' => '03795-00002']);
        $this->tom = Technician::factory()->create(['name' => 'Tom', 'hourly_rate' => '20']);
        $this->shelf = StorageLocation::factory()->create(['name' => 'Main shelf']);

        $this->trip = Trip::query()->create(['technician_id' => $this->tom->id, 'status' => 'open', 'planned_date' => '2026-10-01']);
        $this->morning = TripSession::query()->create([
            'trip_id' => $this->trip->id,
            'technician_id' => $this->tom->id,
            'clock_in' => '2026-10-01 08:00:00',
            'clock_out' => '2026-10-01 11:00:00',
            'source' => 'technician',
        ]);
        TripEvent::query()->create(['trip_session_id' => $this->morning->id, 'kind' => 'break_start', 'at' => '2026-10-01 09:00:00']);
        TripEvent::query()->create(['trip_session_id' => $this->morning->id, 'kind' => 'break_end', 'at' => '2026-10-01 09:30:00']);
        $this->afternoon = TripSession::query()->create([
            'trip_id' => $this->trip->id,
            'technician_id' => $this->tom->id,
            'clock_in' => '2026-10-01 13:00:00',
            'clock_out' => '2026-10-01 14:00:00',
            'source' => 'technician',
        ]);
    }

    private function issueAt(Store $store, string $title = 'Oven not heating'): TicketIssue
    {
        $ticket = Ticket::factory()->create(['store_id' => $store->id]);

        return TicketIssue::factory()->create(['ticket_id' => $ticket->id, 'other_title' => $title]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function pay(array $lines, array $extra = [], int $status = 201): array
    {
        $response = $this->postJson('/api/payments', $extra + [
            'paid_at' => '2026-10-05',
            'technician_id' => $this->tom->id,
            'method' => 'zelle',
            'lines' => $lines,
        ], $this->headers());

        $response->assertStatus($status);

        return $response->json();
    }

    private function balance(Part $part, ?StorageLocation $location = null): string
    {
        return number_format((float) StockBalance::query()
            ->where('part_id', $part->id)
            ->where('storage_location_id', ($location ?? $this->shelf)->id)
            ->value('quantity'), 2, '.', '');
    }

    // ------------------------------------------------------------- Hourly

    public function test_hourly_hours_are_the_paid_minutes_of_the_sessions_over_60_times_the_rate(): void
    {
        $issue = $this->issueAt($this->store);

        $data = $this->pay([[
            'type' => 'hourly',
            'store_id' => $this->store->id,
            'trip_session_ids' => [$this->morning->id, $this->afternoon->id],
            'ticket_issue_ids' => [$issue->id],
        ]])['data'];

        // (150 + 60) / 60 = 3.50 h × 20.00 = 70.00
        $this->assertSame('3.50', $data['lines'][0]['hours']);
        $this->assertSame('20.00', $data['lines'][0]['rate']);
        $this->assertFalse($data['lines'][0]['hours_overridden']);
        $this->assertSame('70.00', $data['lines'][0]['amount']);
        $this->assertSame('70.00', $data['total']);
        $this->assertSame([150, 60], array_column($data['lines'][0]['trip_sessions'], 'paid_minutes'));
        $this->assertSame($issue->id, $data['lines'][0]['ticket_issues'][0]['id']);
        $this->assertSame(['03795-00001'], $data['stores']);
        $this->assertSame(['type' => 'technician', 'id' => $this->tom->id, 'name' => 'Tom', 'category' => null], $data['paid_to']);
    }

    public function test_typed_hours_override_the_sessions_and_a_rate_overrides_the_default(): void
    {
        $line = $this->pay([[
            'type' => 'hourly',
            'store_id' => $this->store->id,
            'trip_session_ids' => [$this->afternoon->id],
            'hours' => '2',
            'rate' => '30',
        ]])['data']['lines'][0];

        $this->assertSame('2.00', $line['hours']);
        $this->assertTrue($line['hours_overridden']);
        $this->assertSame('60.00', $line['amount']);
        $this->assertSame(60, $line['trip_sessions'][0]['paid_minutes']);
    }

    public function test_a_session_already_paid_is_refused_naming_the_payment(): void
    {
        $first = $this->pay([['type' => 'hourly', 'store_id' => $this->store->id, 'trip_session_ids' => [$this->morning->id]]])['data'];

        $errors = $this->pay([['type' => 'hourly', 'store_id' => $this->store->id, 'trip_session_ids' => [$this->morning->id]]], [], 422)['errors'];

        $this->assertStringContainsString("(#{$this->morning->id}) is already paid on payment #{$first['id']}.", $errors['lines.0.trip_session_ids'][0]);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_hourly_needs_a_technician_with_a_rate_and_his_own_closed_sessions(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Home Depot']);
        $this->pay([['type' => 'hourly', 'store_id' => $this->store->id, 'hours' => 1, 'rate' => 10]], ['technician_id' => null, 'supplier_id' => $supplier->id], 422);

        $noRate = Technician::factory()->create(['name' => 'Ned']);
        $this->pay([['type' => 'hourly', 'store_id' => $this->store->id, 'hours' => 1]], ['technician_id' => $noRate->id], 422);

        // Tom's session paid to Ned.
        $errors = $this->pay([['type' => 'hourly', 'store_id' => $this->store->id, 'trip_session_ids' => [$this->morning->id], 'rate' => 5]], ['technician_id' => $noRate->id], 422)['errors'];
        $this->assertStringContainsString("is not one of Ned's sessions", $errors['lines.0.trip_session_ids'][0]);

        $open = TripSession::query()->create(['trip_id' => $this->trip->id, 'technician_id' => $this->tom->id, 'clock_in' => now()->subHour()]);
        $this->pay([['type' => 'hourly', 'store_id' => $this->store->id, 'trip_session_ids' => [$open->id]]], [], 422);
    }

    // -------------------------------------------------- Fixed, inspection, gas, other

    public function test_fixed_inspection_gas_and_other_lines_and_the_total(): void
    {
        $issue = $this->issueAt($this->store);
        $inspection = IssueInspection::query()->create(['ticket_issue_id' => $issue->id, 'technician_id' => $this->tom->id, 'status' => 'done']);

        $data = $this->pay([
            ['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => '100'],
            ['type' => 'inspection', 'store_id' => $this->store->id, 'amount' => '50', 'issue_inspection_id' => $inspection->id],
            ['type' => 'gas', 'store_id' => $this->otherStore->id, 'miles' => '10', 'rate' => '0.65'],
            ['type' => 'gas', 'other_store' => 'Warehouse', 'amount' => '12'],
            ['type' => 'other', 'store_id' => $this->store->id, 'amount' => '5.5', 'description' => 'Parking'],
        ])['data'];

        $this->assertSame(['100.00', '50.00', '6.50', '12.00', '5.50'], array_column($data['lines'], 'amount'));
        $this->assertSame('174.00', $data['total']);
        $this->assertSame($issue->id, $data['lines'][1]['ticket_issues'][0]['id']);
        $this->assertSame('10.00', $data['lines'][2]['miles']);
        $this->assertSame(['03795-00001', '03795-00002', 'Warehouse'], $data['stores']);
        $this->assertSame(['fixed', 'inspection', 'gas', 'other'], $data['types']);

        $this->pay([['type' => 'fixed', 'store_id' => $this->store->id]], [], 422);
        $this->pay([['type' => 'gas', 'store_id' => $this->store->id, 'miles' => 3]], [], 422);
    }

    public function test_every_line_is_spent_in_exactly_one_place_and_the_payment_goes_to_one_payee(): void
    {
        $this->pay([['type' => 'fixed', 'amount' => 1]], [], 422);
        $this->pay([['type' => 'fixed', 'amount' => 1, 'store_id' => $this->store->id, 'other_store' => 'X']], [], 422);
        $this->pay([['type' => 'fixed', 'amount' => 1, 'storage_location_id' => $this->shelf->id]], [], 422);

        $supplier = Supplier::query()->create(['name' => 'Home Depot']);
        $this->pay([['type' => 'fixed', 'amount' => 1, 'store_id' => $this->store->id]], ['supplier_id' => $supplier->id], 422)['errors']['technician_id'];
        $this->pay([['type' => 'fixed', 'amount' => 1, 'store_id' => $this->store->id]], ['technician_id' => null], 422);

        // The issues paid for must be at the line's store.
        $elsewhere = $this->issueAt($this->otherStore);
        $this->pay([['type' => 'fixed', 'amount' => 1, 'store_id' => $this->store->id, 'ticket_issue_ids' => [$elsewhere->id]]], [], 422);
        $this->assertSame(0, Payment::query()->count());
    }

    // -------------------------------------------------------------- Parts

    public function test_parts_into_storage_raise_the_balance_and_create_units_with_serials(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Home Depot']);
        $part = Part::factory()->create(['name' => 'Compressor', 'track_serials' => true]);
        $level = $this->shelf->placeLevels()->create(['name' => 'Shelf', 'sort_order' => 1]);
        $c = $level->placeValues()->create(['value' => 'C', 'sort_order' => 1]);

        $data = $this->pay([[
            'type' => 'parts',
            'storage_location_id' => $this->shelf->id,
            'destination' => 'storage',
            'part_id' => $part->id,
            'quantity' => 2,
            'unit_cost' => '40.25',
            'bought_from_supplier_id' => $supplier->id,
            'serials' => ['SN-1', 'SN-2'],
            'place' => [['level_id' => $level->id, 'value_id' => $c->id]],
        ]], ['technician_id' => null, 'supplier_id' => $supplier->id])['data'];

        $line = $data['lines'][0];
        $this->assertSame('80.50', $line['amount']);
        $this->assertSame('storage', $line['destination']);
        $this->assertSame(['SN-1', 'SN-2'], $line['serials']);
        $this->assertSame('Home Depot', $line['bought_from']['name']);
        $this->assertSame('supplier', $data['paid_to']['type']);

        $this->assertSame('2.00', $this->balance($part));
        $movement = StockMovement::query()->findOrFail($line['stock_movement_id']);
        $this->assertSame(StockMovementType::Bought, $movement->type);
        $this->assertSame($line['id'], $movement->payment_line_id);

        $units = PartUnit::query()->where('part_id', $part->id)->get();
        $this->assertSame(['in_stock', 'in_stock'], $units->pluck('status')->all());
        $this->assertSame([$this->shelf->id, $this->shelf->id], $units->pluck('storage_location_id')->all());
        $this->assertSame([$line['id'], $line['id']], $units->pluck('payment_line_id')->all());

        // The movement shows where it came from; the balance where it sits.
        $this->getJson("/api/stock-movements/{$movement->id}", $this->headers())
            ->assertJsonPath('data.payment.id', $data['id'])
            ->assertJsonPath('data.payment.paid_to.name', 'Home Depot')
            ->assertJsonPath('data.lines.0.serials', ['SN-1', 'SN-2']);
        $this->getJson("/api/stock-balances?part_ids[]={$part->id}", $this->headers())
            ->assertJsonPath('data.0.place.0.value', 'C');

        // A bought movement is undone through its payment, not on its own.
        $this->postJson("/api/stock-movements/{$movement->id}/mistaken", [], $this->headers())->assertUnprocessable();

        // Serial count must match the quantity.
        $this->pay([[
            'type' => 'parts', 'storage_location_id' => $this->shelf->id, 'part_id' => $part->id,
            'quantity' => 2, 'unit_cost' => 1, 'serials' => ['SN-9'],
        ]], [], 422);
        // A serial already recorded.
        $this->pay([[
            'type' => 'parts', 'storage_location_id' => $this->shelf->id, 'part_id' => $part->id,
            'quantity' => 1, 'unit_cost' => 1, 'serials' => ['SN-1'],
        ]], [], 422);
        $this->assertSame('2.00', $this->balance($part));
    }

    public function test_parts_bought_enter_storage_on_the_paid_day_in_every_time_zone(): void
    {
        $part = Part::factory()->create(['name' => 'Fuse']);
        \Illuminate\Support\Carbon::setTestNow('2026-10-31 16:59:00');

        try {
            // An earlier day: noon UTC, so it reads as that day east and west of UTC.
            $earlier = $this->pay([[
                'type' => 'parts', 'storage_location_id' => $this->shelf->id, 'destination' => 'storage',
                'part_id' => $part->id, 'quantity' => 1, 'unit_cost' => 2,
            ]], ['paid_at' => '2026-10-05'])['data'];
            $movement = StockMovement::query()->findOrFail($earlier['lines'][0]['stock_movement_id']);
            $this->assertSame('2026-10-05 12:00:00', $movement->moved_at->format('Y-m-d H:i:s'));

            // Paid today: stamped when it was recorded.
            $today = $this->pay([[
                'type' => 'parts', 'storage_location_id' => $this->shelf->id, 'destination' => 'storage',
                'part_id' => $part->id, 'quantity' => 1, 'unit_cost' => 2,
            ]], ['paid_at' => '2026-10-31'])['data'];
            $movement = StockMovement::query()->findOrFail($today['lines'][0]['stock_movement_id']);
            $this->assertSame('2026-10-31 16:59:00', $movement->moved_at->format('Y-m-d H:i:s'));
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_a_storage_parts_line_shows_where_the_part_sits_now(): void
    {
        $part = Part::factory()->create(['name' => 'Air filter']);
        $shelfLevel = $this->shelf->placeLevels()->create(['name' => 'Shelf', 'sort_order' => 1]);
        $rowLevel = $this->shelf->placeLevels()->create(['name' => 'Row', 'sort_order' => 2]);
        $c = $shelfLevel->placeValues()->create(['value' => 'C', 'sort_order' => 1]);
        $d = $shelfLevel->placeValues()->create(['value' => 'D', 'sort_order' => 2]);
        $eight = $rowLevel->placeValues()->create(['value' => '8', 'sort_order' => 1]);
        $issue = $this->issueAt($this->store);

        $data = $this->pay([
            [
                'type' => 'parts', 'storage_location_id' => $this->shelf->id, 'destination' => 'storage',
                'part_id' => $part->id, 'quantity' => 2, 'unit_cost' => '3',
                'place' => [['level_id' => $rowLevel->id, 'value_id' => $eight->id], ['level_id' => $shelfLevel->id, 'value_id' => $c->id]],
            ],
            ['type' => 'parts', 'store_id' => $this->store->id, 'destination' => 'ticket', 'ticket_issue_ids' => [$issue->id], 'part_id' => $part->id, 'quantity' => 1, 'unit_cost' => '3'],
            ['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => 10],
        ])['data'];

        $this->assertSame([
            ['level_id' => $shelfLevel->id, 'level' => 'Shelf', 'value_id' => $c->id, 'value' => 'C'],
            ['level_id' => $rowLevel->id, 'level' => 'Row', 'value_id' => $eight->id, 'value' => '8'],
        ], $data['lines'][0]['place']);
        $this->assertNull($data['lines'][1]['place']);
        $this->assertNull($data['lines'][2]['place']);

        // Re-shelved since: the payment shows the place it has now.
        $balance = StockBalance::query()->where('part_id', $part->id)->where('storage_location_id', $this->shelf->id)->firstOrFail();
        $this->putJson("/api/stock-balances/{$balance->id}/place", ['place_value_ids' => [$d->id]], $this->headers())->assertOk();

        $this->getJson("/api/payments/{$data['id']}", $this->headers())->assertOk()
            ->assertJsonPath('data.lines.0.place', [['level_id' => $shelfLevel->id, 'level' => 'Shelf', 'value_id' => $d->id, 'value' => 'D']]);

        // A storage line nobody placed is an empty address, not null.
        $other = Part::factory()->create(['name' => 'Belt']);
        $unplaced = $this->pay([['type' => 'parts', 'storage_location_id' => $this->shelf->id, 'part_id' => $other->id, 'quantity' => 1, 'unit_cost' => '1']])['data'];
        $this->assertSame([], $unplaced['lines'][0]['place']);
    }

    public function test_parts_for_a_ticket_link_the_issue_and_install_the_units_without_stock(): void
    {
        $issue = $this->issueAt($this->store, 'Walk-in cooler');
        $part = Part::factory()->create(['name' => 'Fan motor', 'track_serials' => true]);

        $line = $this->pay([[
            'type' => 'parts',
            'store_id' => $this->store->id,
            'destination' => 'ticket',
            'ticket_issue_ids' => [$issue->id],
            'part_id' => $part->id,
            'quantity' => 1,
            'unit_cost' => '75',
            'serials' => ['FM-1'],
        ]])['data']['lines'][0];

        $this->assertSame('ticket', $line['destination']);
        $this->assertNull($line['stock_movement_id']);
        $this->assertSame('Walk-in cooler', $line['ticket_issues'][0]['title']);
        $this->assertSame('03795-00001', $line['ticket_issues'][0]['store_number']);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame([$line['id']], $issue->paymentLines()->pluck('payment_lines.id')->all());

        $unit = PartUnit::query()->where('serial_number', 'FM-1')->sole();
        $this->assertSame('installed', $unit->status);
        $this->assertSame($issue->id, $unit->ticket_issue_id);
        $this->assertNotNull($unit->installed_at);

        // A ticket line needs its issues.
        $this->pay([['type' => 'parts', 'store_id' => $this->store->id, 'destination' => 'ticket', 'part_id' => $part->id, 'quantity' => 1, 'unit_cost' => 1, 'serials' => ['FM-2']]], [], 422);
    }

    public function test_parts_lines_pay_back_technician_purchases(): void
    {
        $part = Part::factory()->create(['name' => 'Fuse']);
        $mine = TechnicianPurchase::query()->create([
            'technician_id' => $this->tom->id, 'trip_id' => $this->trip->id, 'part_id' => $part->id,
            'description' => 'Fuses', 'quantity' => 2, 'total_cost' => '9.80', 'bought_at' => '2026-10-01 10:00:00',
        ]);
        $ned = Technician::factory()->create(['name' => 'Ned']);
        $his = TechnicianPurchase::query()->create([
            'technician_id' => $ned->id, 'description' => 'Tape', 'total_cost' => '3.00', 'bought_at' => '2026-10-01 10:00:00',
        ]);
        $issue = $this->issueAt($this->store);

        $refused = $this->pay([[
            'type' => 'parts', 'store_id' => $this->store->id, 'ticket_issue_ids' => [$issue->id], 'part_id' => $part->id,
            'quantity' => 2, 'unit_cost' => '4.90', 'technician_purchase_ids' => [$his->id],
        ]], [], 422);
        $this->assertArrayHasKey('lines.0.technician_purchase_ids', $refused['errors']);

        $data = $this->pay([[
            'type' => 'parts', 'store_id' => $this->store->id, 'ticket_issue_ids' => [$issue->id], 'part_id' => $part->id,
            'quantity' => 2, 'unit_cost' => '4.90', 'technician_purchase_ids' => [$mine->id],
        ]])['data'];

        $this->assertSame('9.80', $data['lines'][0]['amount']);
        $this->assertSame($data['lines'][0]['id'], $mine->fresh()->payment_line_id);
        $this->assertTrue($data['lines'][0]['technician_purchases'][0]['paid']);
        $this->assertSame($data['id'], $data['lines'][0]['technician_purchases'][0]['payment_id']);

        // Paid twice: refused.
        $this->pay([[
            'type' => 'parts', 'store_id' => $this->store->id, 'ticket_issue_ids' => [$issue->id], 'part_id' => $part->id,
            'quantity' => 2, 'unit_cost' => '4.90', 'technician_purchase_ids' => [$mine->id],
        ]], [], 422);
    }

    public function test_an_other_line_pays_back_a_purchase_that_is_not_a_catalog_part(): void
    {
        $ties = TechnicianPurchase::query()->create([
            'technician_id' => $this->tom->id, 'trip_id' => $this->trip->id,
            'description' => 'Zip ties', 'total_cost' => '6.50', 'bought_at' => '2026-10-01 10:00:00',
        ]);

        // Only parts and Other lines pay purchases back.
        $refused = $this->pay([[
            'type' => 'fixed', 'store_id' => $this->store->id, 'amount' => '6.50', 'technician_purchase_ids' => [$ties->id],
        ]], [], 422);
        $this->assertArrayHasKey('lines.0.technician_purchase_ids', $refused['errors']);

        $data = $this->pay([[
            'type' => 'other', 'store_id' => $this->store->id, 'description' => 'Zip ties he bought',
            'amount' => '6.50', 'technician_purchase_ids' => [$ties->id],
        ]])['data'];

        $this->assertSame('6.50', $data['lines'][0]['amount']);
        $this->assertSame($data['lines'][0]['id'], $ties->fresh()->payment_line_id);
        $this->assertTrue($data['lines'][0]['technician_purchases'][0]['paid']);

        // Paid twice: refused, also on another Other line.
        $this->pay([[
            'type' => 'other', 'store_id' => $this->store->id, 'amount' => '6.50', 'technician_purchase_ids' => [$ties->id],
        ]], [], 422);

        // Marking the payment mistaken releases it again.
        $this->postJson("/api/payments/{$data['id']}/mistaken", ['reason' => 'Wrong technician'], $this->headers())->assertOk();
        $this->assertNull($ties->fresh()->payment_line_id);
    }

    // --------------------------------------------------------- Edit, mistaken

    public function test_edit_replaces_the_lines_and_restores_the_stock_the_old_ones_bought(): void
    {
        $part = Part::factory()->create(['name' => 'Air filter']);
        $tracked = Part::factory()->create(['name' => 'Compressor', 'track_serials' => true]);

        $data = $this->pay([
            ['type' => 'parts', 'storage_location_id' => $this->shelf->id, 'part_id' => $part->id, 'quantity' => 5, 'unit_cost' => '4'],
            ['type' => 'parts', 'storage_location_id' => $this->shelf->id, 'part_id' => $tracked->id, 'quantity' => 1, 'unit_cost' => '100', 'serials' => ['SN-1']],
            ['type' => 'hourly', 'store_id' => $this->store->id, 'trip_session_ids' => [$this->morning->id]],
        ])['data'];
        $this->assertSame('5.00', $this->balance($part));
        $this->assertSame('170.00', $data['total']);

        $this->travel(5)->seconds();

        $edited = $this->postJson("/api/payments/{$data['id']}/edit", [
            'expected_updated_at' => $data['updated_at'],
            'paid_at' => '2026-10-06',
            'technician_id' => $this->tom->id,
            'invoice_number' => 'INV-77',
            'lines' => [
                ['type' => 'parts', 'storage_location_id' => $this->shelf->id, 'part_id' => $part->id, 'quantity' => 3, 'unit_cost' => '4'],
                // The same serial again: the old line's unit was retired first.
                ['type' => 'parts', 'storage_location_id' => $this->shelf->id, 'part_id' => $tracked->id, 'quantity' => 1, 'unit_cost' => '90', 'serials' => ['SN-1']],
                ['type' => 'hourly', 'store_id' => $this->store->id, 'trip_session_ids' => [$this->morning->id, $this->afternoon->id]],
            ],
        ], $this->headers())->assertOk()->json('data');

        $this->assertSame('3.00', $this->balance($part));
        $this->assertSame('1.00', $this->balance($tracked));
        $this->assertSame('INV-77', $edited['invoice_number']);
        $this->assertSame('2026-10-06', $edited['paid_at']);
        $this->assertSame('172.00', $edited['total']); // 12 + 90 + 3.5 h × 20
        $this->assertCount(3, $edited['lines']);
        $this->assertCount(1, $edited['revisions']);
        $this->assertSame(1, PartUnit::query()->where('serial_number', 'SN-1')->count());

        // The old purchase was undone, not deleted.
        $this->assertSame(1, StockMovement::query()->where('type', 'undone')->whereHas('lines', fn ($q) => $q->where('part_id', $part->id))->count());
        $this->assertSame(2, StockMovement::query()->where('type', 'bought')->whereHas('lines', fn ($q) => $q->where('part_id', $part->id))->count());

        $snapshot = PaymentRevision::query()->sole()->snapshot;
        $this->assertSame('170.00', $snapshot['total']);
    }

    public function test_a_stale_edit_is_refused_with_409(): void
    {
        $data = $this->pay([['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => 10]])['data'];
        $body = fn (string $at, string $amount) => [
            'expected_updated_at' => $at,
            'paid_at' => '2026-10-05',
            'technician_id' => $this->tom->id,
            'lines' => [['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => $amount]],
        ];

        $this->travel(5)->seconds();
        $this->postJson("/api/payments/{$data['id']}/edit", $body($data['updated_at'], '20'), $this->headers())->assertOk();

        $this->travel(5)->seconds();
        $this->postJson("/api/payments/{$data['id']}/edit", $body($data['updated_at'], '30'), $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('message', 'Someone else changed this payment after you opened it. Reload it and make your change again.');

        $this->assertSame('20.00', (string) Payment::query()->sole()->total);

        $this->postJson("/api/payments/{$data['id']}/edit", ['paid_at' => '2026-10-05'] + $body($data['updated_at'], '1'), $this->headers())->assertStatus(409);
        $this->postJson("/api/payments/{$data['id']}/edit", array_diff_key($body('x', '1'), ['expected_updated_at' => 1]), $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('expected_updated_at');
    }

    public function test_mistaken_restores_stock_releases_sessions_and_purchases_and_keeps_the_lines(): void
    {
        $part = Part::factory()->create(['name' => 'Air filter']);
        $purchase = TechnicianPurchase::query()->create([
            'technician_id' => $this->tom->id, 'description' => 'Filters', 'total_cost' => '8', 'bought_at' => '2026-10-01 10:00:00',
        ]);

        $data = $this->pay([
            ['type' => 'parts', 'storage_location_id' => $this->shelf->id, 'part_id' => $part->id, 'quantity' => 4, 'unit_cost' => '2', 'technician_purchase_ids' => [$purchase->id]],
            ['type' => 'hourly', 'store_id' => $this->store->id, 'trip_session_ids' => [$this->morning->id]],
        ])['data'];
        $this->assertSame('4.00', $this->balance($part));

        $this->postJson("/api/payments/{$data['id']}/mistaken", [], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->getJson("/api/payments/{$data['id']}", $this->headers())->assertJsonPath('data.mistaken_reason', null);

        $this->postJson("/api/payments/{$data['id']}/mistaken", ['reason' => 'Paid twice'], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.mistaken', true)
            ->assertJsonPath('data.mistaken_reason', 'Paid twice')
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.notes.0.body', 'Paid twice');

        $this->assertSame('0.00', $this->balance($part));
        $this->assertNull($purchase->fresh()->payment_line_id);
        $this->assertSame(0, $this->morning->paymentLines()->count());

        $this->postJson("/api/payments/{$data['id']}/mistaken", ['reason' => 'again'], $this->headers())->assertUnprocessable();
        $this->postJson("/api/payments/{$data['id']}/edit", [
            'expected_updated_at' => Payment::query()->find($data['id'])->updated_at->toIso8601String(),
            'paid_at' => '2026-10-05', 'technician_id' => $this->tom->id,
            'lines' => [['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => 1]],
        ], $this->headers())->assertUnprocessable();

        // The session can be paid again.
        $this->pay([['type' => 'hourly', 'store_id' => $this->store->id, 'trip_session_ids' => [$this->morning->id]]]);

        // The list hides mistaken payments unless asked.
        $this->getJson('/api/payments', $this->headers())->assertJsonCount(1, 'data')->assertJsonPath('meta.totals.count', 1);
        $rows = collect($this->getJson('/api/payments?include_mistaken=1', $this->headers())->assertJsonCount(2, 'data')->json('data'));
        $this->assertSame('Paid twice', $rows->firstWhere('id', $data['id'])['mistaken_reason']);
        $this->assertNull($rows->firstWhere('id', '!=', $data['id'])['mistaken_reason']);
        $this->getJson("/api/payments/{$data['id']}", $this->headers())->assertJsonPath('data.mistaken_reason', 'Paid twice');
    }

    // ------------------------------------------------------- List, multipart

    public function test_the_list_filters_and_totals(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Home Depot']);
        $category = Category::query()->where('name', 'Company')->firstOrFail();
        $this->tom->update(['category_id' => $category->id]);

        $this->pay([['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => 100]], ['invoice_number' => 'INV-1', 'paid_at' => '2026-10-01']);
        $this->pay([['type' => 'gas', 'store_id' => $this->otherStore->id, 'amount' => 20]], ['paid_at' => '2026-10-10']);
        $this->pay([['type' => 'other', 'store_id' => $this->store->id, 'amount' => 5]], ['technician_id' => null, 'supplier_id' => $supplier->id, 'reference' => 'CHK-9']);

        $this->getJson('/api/payments', $this->headers())
            ->assertOk()
            ->assertJsonPath('meta.totals.count', 3)
            ->assertJsonPath('meta.totals.total', '125.00')
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/payments?store=03795-00001', $this->headers())->assertJsonPath('meta.totals.total', '105.00');
        $this->getJson('/api/payments?type=gas', $this->headers())->assertJsonPath('meta.totals.total', '20.00');
        $this->getJson("/api/payments?technician_id={$this->tom->id}", $this->headers())->assertJsonPath('meta.totals.count', 2);
        $this->getJson("/api/payments?supplier_id={$supplier->id}", $this->headers())->assertJsonPath('data.0.paid_to.name', 'Home Depot');
        $this->getJson("/api/payments?category_id={$category->id}", $this->headers())
            ->assertJsonPath('meta.totals.count', 2)
            ->assertJsonPath('data.0.paid_to.category.name', 'Company');
        $this->getJson('/api/payments?from=2026-10-02&to=2026-10-31', $this->headers())->assertJsonPath('meta.totals.count', 2);
        $this->getJson('/api/payments?search=inv-1', $this->headers())->assertJsonPath('meta.totals.count', 1);
        $this->getJson('/api/payments?search=chk', $this->headers())->assertJsonPath('meta.totals.count', 1);
        $this->getJson('/api/payments?search=depot', $this->headers())->assertJsonPath('meta.totals.count', 1);
        $this->getJson('/api/payments?search=tom', $this->headers())->assertJsonPath('meta.totals.count', 2);
    }

    public function test_multipart_with_lines_as_a_json_string_and_an_invoice_file(): void
    {
        $response = $this->post('/api/payments', [
            'paid_at' => '2026-10-05',
            'technician_id' => $this->tom->id,
            'note' => 'Paid at the store',
            'lines' => json_encode([['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => '45']]),
            'files' => [UploadedFile::fake()->create('invoice.pdf', 20)],
        ], $this->headers())->assertCreated();

        $response->assertJsonPath('data.total', '45.00')
            ->assertJsonPath('data.has_invoice', true)
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.notes.0.body', 'Paid at the store');

        $id = $response->json('data.id');
        $this->postJson("/api/payments/{$id}/notes", ['body' => 'Receipt checked'], $this->headers())->assertCreated();
        $this->post("/api/payments/{$id}/attachments", ['files' => [UploadedFile::fake()->create('r.pdf', 5)]], $this->headers())->assertCreated();
        $this->getJson("/api/payments/{$id}", $this->headers())->assertJsonCount(2, 'data.attachments')->assertJsonCount(2, 'data.notes');

        // Bracket notation works too.
        $this->post('/api/payments', [
            'paid_at' => '2026-10-05',
            'technician_id' => $this->tom->id,
            'lines' => [['type' => 'other', 'store_id' => $this->store->id, 'amount' => '3']],
        ], $this->headers())->assertCreated()->assertJsonPath('data.total', '3.00');
    }

    // ------------------------------------------------------------ Unpaid work

    public function test_unpaid_work_lists_unpaid_sessions_and_purchases_per_technician(): void
    {
        $issue = $this->issueAt($this->store, 'Fryer');
        $stop = $this->trip->stops()->create(['store_id' => $this->store->id, 'status' => 'done']);
        $stop->ticketIssues()->attach($issue->id, ['purpose' => 'work']);

        TechnicianPurchase::query()->create([
            'technician_id' => $this->tom->id, 'description' => 'Fuses', 'total_cost' => '9.80', 'bought_at' => '2026-10-01 10:00:00',
        ]);
        TechnicianPurchase::query()->create([
            'technician_id' => $this->tom->id, 'description' => 'Wrong', 'total_cost' => '50', 'bought_at' => '2026-10-01 10:00:00', 'mistaken' => true,
        ]);
        $ned = Technician::factory()->create(['name' => 'Ned']);
        TechnicianPurchase::query()->create([
            'technician_id' => $ned->id, 'description' => 'Tape', 'total_cost' => '3.00', 'bought_at' => '2026-10-01 10:00:00',
        ]);

        $this->pay([['type' => 'hourly', 'store_id' => $this->store->id, 'trip_session_ids' => [$this->afternoon->id]]]);

        $rows = $this->getJson('/api/unpaid-work', $this->headers())->assertOk()->json('data');
        $this->assertSame(['Ned', 'Tom'], array_column(array_column($rows, 'technician'), 'name'));

        $tom = $rows[1];
        $this->assertSame([$this->morning->id], array_column($tom['sessions'], 'id'));
        $this->assertSame(150, $tom['sessions'][0]['paid_minutes']);
        $this->assertSame(30, $tom['sessions'][0]['durations']['break']);
        $this->assertSame('Fryer', $tom['sessions'][0]['ticket_issues'][0]['title']);
        $this->assertSame(['Fuses'], array_column($tom['purchases'], 'description'));
        $this->assertSame(['paid_minutes' => 150, 'purchases_total' => '9.80'], $tom['totals']);

        $this->getJson("/api/unpaid-work?technician_id={$ned->id}", $this->headers())
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sessions', [])
            ->assertJsonPath('data.0.totals.purchases_total', '3.00');
    }
}
