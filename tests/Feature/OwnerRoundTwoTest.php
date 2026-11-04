<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\Part;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\StorageLocation;

/**
 * The owner's second round: time windows on bookings, an inspection's own
 * fee, payment methods from a catalog, the technician on a stock movement,
 * the fix summary required to close, no deleting tickets, and finances for
 * several technicians at once.
 */
class OwnerRoundTwoTest extends IssueWorkflowTestCase
{
    public function test_work_and_inspections_are_booked_for_a_time_window(): void
    {
        $this->act('inspections', [
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-11-05',
            'scheduled_time' => '09:00',
            'scheduled_time_to' => '11:30',
        ])->assertOk()
            ->assertJsonPath('data.inspections.0.scheduled_time', '09:00')
            ->assertJsonPath('data.inspections.0.scheduled_time_to', '11:30')
            ->assertJsonPath('data.schedules.0.time', '09:00')
            ->assertJsonPath('data.schedules.0.time_to', '11:30');

        // The end must come after the start, and needs a start.
        $this->act('schedule', ['technician_id' => $this->technician->id, 'date' => '2026-11-06', 'time' => '14:00', 'time_to' => '13:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('time_to');
        $this->act('schedule', ['technician_id' => $this->technician->id, 'date' => '2026-11-06', 'time_to' => '13:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('time');

        $this->act('schedule', ['technician_id' => $this->technician->id, 'date' => '2026-11-06', 'time' => '13:00', 'time_to' => '15:00'])
            ->assertOk()->assertJsonFragment(['kind' => 'work', 'time' => '13:00', 'time_to' => '15:00']);
    }

    public function test_an_inspection_fee_is_its_cost_until_a_payment_line_covers_it(): void
    {
        $issue = $this->act('inspections', [
            'technician_id' => $this->technician->id,
            'scheduled_date' => '2026-11-05',
            'inspection_fee' => '65',
        ])->assertOk()
            ->assertJsonPath('data.inspections.0.inspection_fee', '65.00')
            ->assertJsonPath('data.costs.inspection', '65.00')
            ->assertJsonPath('data.costs.inspection_fees', '65.00')
            ->json('data');

        $inspectionId = $issue['inspections'][0]['id'];

        $this->postJson('/api/payments', [
            'paid_at' => '2026-11-05',
            'technician_id' => $this->technician->id,
            'lines' => [[
                'type' => 'inspection',
                'store_id' => $this->store->id,
                'amount' => '70',
                'issue_inspection_id' => $inspectionId,
                'ticket_issue_ids' => [$this->issue->id],
            ]],
        ], $this->headers())->assertCreated();

        // The paid line takes over: 70, not 65 + 70.
        $this->read()->assertJsonPath('data.costs.inspection', '70.00')->assertJsonPath('data.costs.inspection_fees', '0.00');
    }

    public function test_payment_methods_come_from_an_editable_list(): void
    {
        $this->getJson('/api/payment-methods', $this->headers())->assertOk()
            ->assertJsonPath('data.0.name', 'Cash')
            ->assertJsonCount(6, 'data');

        $card = $this->postJson('/api/payment-methods', ['name' => 'Company  card'], $this->headers())->assertCreated()
            ->assertJsonPath('data.name', 'Company card')->json('data');
        $this->postJson('/api/payment-methods', ['name' => 'company card'], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $payment = $this->postJson('/api/payments', [
            'paid_at' => '2026-11-05',
            'technician_id' => $this->technician->id,
            'payment_method_id' => $card['id'],
            'lines' => [['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => '50']],
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.payment_method.name', 'Company card')->json('data');

        $this->deleteJson("/api/payment-methods/{$card['id']}", [], $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        $this->patchJson("/api/payment-methods/{$card['id']}", ['name' => 'Amex'], $this->headers())->assertOk()->assertJsonPath('data.name', 'Amex');

        Payment::query()->whereKey($payment['id'])->update(['payment_method_id' => null]);
        $this->deleteJson("/api/payment-methods/{$card['id']}", [], $this->headers())->assertNoContent();
        $this->assertNull(PaymentMethod::query()->find($card['id']));
    }

    public function test_a_stock_movement_names_the_technician_who_did_it(): void
    {
        $part = Part::factory()->create(['name' => 'Fan motor']);
        $location = StorageLocation::factory()->create(['name' => 'Main storage']);

        $this->postJson('/api/stock-movements', [
            'moved_at' => '2026-11-04',
            'type' => 'opening_stock',
            'technician_id' => $this->technician->id,
            'lines' => [['part_id' => $part->id, 'storage_location_id' => $location->id, 'quantity' => 3]],
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.technician.name', 'Sam Fixer');
    }

    public function test_closing_needs_what_was_wrong_and_how_it_was_fixed(): void
    {
        $this->putStatus(IssueStatus::WorkDone);

        $this->act('close', ['resolution' => 'not_needed'])->assertUnprocessable()->assertJsonValidationErrors('final_note');
        $this->act('close', ['resolution' => 'not_needed', 'final_note' => 'Worked again after a restart.'])->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_a_closed_issue_can_still_get_its_payment_and_invoice(): void
    {
        $this->putStatus(IssueStatus::Closed);

        $this->postJson('/api/payments', [
            'paid_at' => '2026-11-05',
            'technician_id' => $this->technician->id,
            'lines' => [['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => '90', 'ticket_issue_ids' => [$this->issue->id]]],
        ], $this->headers())->assertCreated();

        $this->read()->assertJsonPath('data.costs.fixed', null)->assertJsonPath('data.costs.labor', '90.00');
    }

    public function test_tickets_can_no_longer_be_deleted(): void
    {
        $this->deleteJson("/api/stores/{$this->store->store_number}/tickets/{$this->ticket->id}", [], $this->headers())
            ->assertStatus(404);
        $this->assertNotSoftDeleted($this->ticket);
    }

    public function test_technicians_filter_by_several_stores(): void
    {
        $other = \App\Models\Store::factory()->create(['store_number' => '03795-00002']);
        $third = \App\Models\Store::factory()->create(['store_number' => '03795-00007']);
        $this->technician->coverageStores()->sync([$this->store->id]);
        \App\Models\Technician::factory()->create(['name' => 'Ana Volt'])->coverageStores()->sync([$other->id]);
        \App\Models\Technician::factory()->create(['name' => 'Bo Pipe'])->coverageStores()->sync([$third->id]);

        $query = http_build_query(['stores' => ['03795-00001', '03795-00002']]);
        $names = collect($this->getJson("/api/technicians?{$query}", $this->headers())->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Ana Volt', 'Sam Fixer'], $names);
    }

    public function test_finances_narrow_to_several_technicians_checked_together(): void
    {
        $other = \App\Models\Technician::factory()->create(['name' => 'Ana Volt']);
        $third = \App\Models\Technician::factory()->create(['name' => 'Bo Pipe']);
        foreach ([[$this->technician, '100'], [$other, '40'], [$third, '7']] as [$tech, $amount]) {
            $this->postJson('/api/payments', [
                'paid_at' => '2026-11-03',
                'technician_id' => $tech->id,
                'lines' => [['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => $amount]],
            ], $this->headers())->assertCreated();
        }

        $query = http_build_query(['from' => '2026-11-01', 'to' => '2026-11-05', 'technician_ids' => [$this->technician->id, $other->id]]);
        $data = $this->getJson("/api/maintenance-analytics/finances?{$query}", $this->headers())->assertOk()->json('data');

        $this->assertSame('140.00', $data['totals']['spent'] ?? $data['total'] ?? null, json_encode(array_keys($data)));
    }
}
