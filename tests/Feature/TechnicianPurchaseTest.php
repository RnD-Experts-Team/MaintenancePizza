<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\TechnicianPurchase;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * What a technician bought with his own money (docs/MAINTENANCE.md §3/§4):
 * from his link and from the dashboard, the supplier by id or by name, and
 * whether it is paid back yet.
 */
class TechnicianPurchaseTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Technician $technician;

    private TicketIssue $issue;

    private Trip $trip;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Carbon::setTestNow('2026-11-02 08:00:00');
        $this->fakeAuthServer();

        $this->technician = Technician::factory()->create(['name' => 'Sam Rivera']);
        $this->issue = TicketIssue::factory()->create(['other_title' => 'Fryer leaking']);
        $result = app(TripService::class)->scheduleIssue($this->issue, $this->technician, '2026-11-02', null, 'work', null, User::factory()->create());
        $this->trip = $result['trip'];
        $this->token = $result['token'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_new_supplier_name_creates_the_supplier_and_the_same_name_in_other_case_reuses_it(): void
    {
        $part = Part::factory()->create(['name' => 'Gasket']);

        $data = $this->postJson("/api/public/trips/{$this->token}/purchases", [
            'description' => 'Drain gasket',
            'part_id' => $part->id,
            'quantity' => 2,
            'total_cost' => '18.40',
            'supplier_name' => '  Home   Depot ',
            'ticket_issue_id' => $this->issue->id,
            'serial_number' => 'SN-1',
            'receipt' => [UploadedFile::fake()->image('receipt.jpg')],
        ])->assertCreated()->json('data.purchases.0');

        $supplier = Supplier::query()->sole();
        $this->assertSame('Home Depot', $supplier->name);
        $this->assertSame(['id' => $supplier->id, 'name' => 'Home Depot'], $data['supplier']);
        $this->assertSame('18.40', $data['total_cost']);
        $this->assertSame('2.00', $data['quantity']);
        $this->assertSame('Fryer leaking', $data['ticket_issue']['title']);
        $this->assertSame(['id' => $part->id, 'name' => 'Gasket'], $data['part']);
        $this->assertSame('receipt.jpg', $data['receipts'][0]['original_name']);
        $this->assertFalse($data['paid']);

        $purchase = TechnicianPurchase::query()->sole();
        $this->assertSame($this->technician->id, $purchase->technician_id);
        $this->assertSame($this->trip->id, $purchase->trip_id);
        $this->assertSame($this->trip->stops()->sole()->id, $purchase->trip_stop_id);
        $this->assertNull($purchase->created_by);

        // Same supplier, typed differently: reused, never a second one.
        $this->postJson("/api/public/trips/{$this->token}/purchases", [
            'description' => 'Tape', 'total_cost' => 4, 'supplier_name' => 'HOME DEPOT',
        ])->assertCreated();

        $this->assertSame(1, Supplier::query()->count());
        $this->assertSame([$supplier->id, $supplier->id], TechnicianPurchase::query()->orderBy('id')->pluck('supplier_id')->all());
    }

    public function test_a_supplier_is_given_by_id_or_by_name_not_both(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Walmart']);

        $this->postJson("/api/public/trips/{$this->token}/purchases", [
            'description' => 'Bulbs', 'total_cost' => 9, 'supplier_id' => $supplier->id, 'supplier_name' => 'Target',
        ])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');

        $this->postJson("/api/public/trips/{$this->token}/purchases", [
            'description' => 'Bulbs', 'total_cost' => 9, 'supplier_id' => $supplier->id,
        ])->assertCreated()->assertJsonPath('data.purchases.0.supplier.name', 'Walmart');

        $this->assertSame(1, Supplier::query()->count());
    }

    public function test_a_purchase_on_the_link_must_be_for_a_job_on_this_trip(): void
    {
        $this->postJson("/api/public/trips/{$this->token}/purchases", [
            'description' => 'Bulbs', 'total_cost' => 9, 'ticket_issue_id' => TicketIssue::factory()->create()->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('ticket_issue_id');

        $this->postJson("/api/public/trips/{$this->token}/purchases", ['total_cost' => 9])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
    }

    public function test_the_dashboard_records_lists_and_marks_purchases_mistaken(): void
    {
        $other = Technician::factory()->create();

        $this->postJson('/api/technician-purchases', [
            'technician_id' => $other->id, 'trip_id' => $this->trip->id, 'description' => 'x', 'total_cost' => 1,
        ], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('trip_id');

        $created = $this->postJson('/api/technician-purchases', [
            'technician_id' => $this->technician->id,
            'trip_id' => $this->trip->id,
            'ticket_issue_id' => $this->issue->id,
            'description' => 'Thermocouple',
            'total_cost' => '32.00',
            'supplier_name' => 'Amazon',
        ], $this->headers())->assertCreated()->json('data');

        $this->assertSame('Sam Rivera', $created['technician']['name']);
        $this->assertSame($this->trip->id, $created['trip_id']);
        $this->assertSame('Amazon', $created['supplier']['name']);
        $this->assertSame($this->authUser->id, TechnicianPurchase::query()->find($created['id'])->created_by);

        $paidBack = TechnicianPurchase::query()->create([
            'technician_id' => $this->technician->id, 'description' => 'Paid', 'total_cost' => 5, 'bought_at' => now(),
        ]);
        $paidBack->update(['payment_line_id' => $this->line()->id]);
        $released = TechnicianPurchase::query()->create([
            'technician_id' => $this->technician->id, 'description' => 'Released', 'total_cost' => 6, 'bought_at' => now(),
        ]);
        $released->update(['payment_line_id' => $this->line(true)->id]);

        $unpaid = $this->getJson("/api/technician-purchases?technician_id={$this->technician->id}&unpaid=1", $this->headers())
            ->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$created['id'], $released->id], array_column($unpaid, 'id'));
        $this->assertCount(3, $this->getJson("/api/technician-purchases?technician_id={$this->technician->id}", $this->headers())->json('data'));

        $paid = collect($this->getJson('/api/technician-purchases', $this->headers())->json('data'))->firstWhere('id', $paidBack->id);
        $this->assertTrue($paid['paid']);
        $this->assertNotNull($paid['payment_id']);

        // A paid-back purchase cannot be a mistake; an unpaid one can.
        $this->postJson("/api/technician-purchases/{$paidBack->id}/mistaken", [], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/technician-purchases/{$created['id']}/mistaken", ['reason' => 'Typed twice'], $this->headers())
            ->assertOk()->assertJsonPath('data.mistaken', true);

        $this->assertSame('Marked as a mistake: Typed twice', TechnicianPurchase::query()->find($created['id'])->notes()->sole()->body);
        $this->assertNotContains($created['id'], array_column(
            $this->getJson('/api/technician-purchases?unpaid=1', $this->headers())->json('data'), 'id'));
    }

    private function line(bool $mistakenPayment = false): PaymentLine
    {
        $payment = Payment::query()->create([
            'paid_at' => '2026-11-05', 'technician_id' => $this->technician->id, 'total' => '5.00', 'mistaken' => $mistakenPayment,
        ]);

        return PaymentLine::query()->create([
            'payment_id' => $payment->id, 'type' => 'parts', 'store_id' => Store::factory()->create()->id, 'amount' => '5.00',
        ]);
    }
}
