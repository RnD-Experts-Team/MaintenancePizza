<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * Refunds (docs/MAINTENANCE.md §4): money that came back for a payment, a
 * payment row of its own with negative lines, over HTTP.
 */
class PaymentRefundTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    private Supplier $shop;

    private TicketIssue $issue;

    /** @var array<string, mixed> the payment refunded: 300 at store 1 (ticket), 100 at store 2 */
    private array $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();

        $this->store = Store::factory()->create(['store_number' => '03795-00001']);
        $this->otherStore = Store::factory()->create(['store_number' => '03795-00002']);
        $this->shop = Supplier::query()->create(['name' => 'Parts Town']);
        $ticket = Ticket::factory()->create(['store_id' => $this->store->id]);
        $this->issue = TicketIssue::factory()->create(['ticket_id' => $ticket->id, 'other_title' => 'Oven conveyor']);

        $this->payment = $this->postJson('/api/payments', [
            'paid_at' => '2026-10-05',
            'supplier_id' => $this->shop->id,
            'payment_method_id' => PaymentMethod::query()->where('name', 'Card')->value('id'),
            'lines' => [
                ['type' => 'other', 'store_id' => $this->store->id, 'amount' => 300, 'description' => 'Conveyor shaft', 'ticket_issue_ids' => [$this->issue->id]],
                ['type' => 'other', 'store_id' => $this->otherStore->id, 'amount' => 100, 'description' => 'Gaskets'],
            ],
        ], $this->headers())->assertCreated()->json('data');
    }

    /**
     * @param  array<int, array{0: int, 1: mixed}>  $lines  [line index, amount]
     * @param  array<string, mixed>  $extra
     */
    private function refund(array $lines, array $extra = [], int $status = 201, ?int $paymentId = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/payments/'.($paymentId ?? $this->payment['id']).'/refunds', $extra + [
            'refunded_at' => '2026-10-20',
            'payment_method_id' => PaymentMethod::query()->where('name', 'Card')->value('id'),
            'reason' => 'Shaft returned, wrong size.',
            'lines' => array_map(fn ($l) => ['line_id' => $this->payment['lines'][$l[0]]['id'], 'amount' => $l[1]], $lines),
        ], $this->headers())->assertStatus($status);
    }

    private function refundRow(): Payment
    {
        return Payment::query()->where('kind', 'refund')->latest('id')->firstOrFail();
    }

    public function test_a_refund_is_a_payment_of_its_own_with_negative_lines_that_copy_the_refunded_lines(): void
    {
        $data = $this->refund([[0, '120.50']])->json('data');

        // The payment answers with its refunds and what is left.
        $this->assertSame('payment', $data['kind']);
        $this->assertSame('400.00', $data['total']);
        $this->assertSame('120.50', $data['refunded_total']);
        $this->assertSame('279.50', $data['net_total']);
        $this->assertSame('120.50', $data['refunds'][0]['amount']);
        $this->assertSame('2026-10-20', $data['refunds'][0]['refunded_at']);
        $this->assertSame('Shaft returned, wrong size.', $data['refunds'][0]['reason']);
        $this->assertSame('Card', $data['refunds'][0]['payment_method']['name']);
        $this->assertSame(['120.50', '179.50'], [$data['lines'][0]['refunded'], $data['lines'][0]['refundable']]);
        $this->assertSame(['0.00', '100.00'], [$data['lines'][1]['refunded'], $data['lines'][1]['refundable']]);

        // The refund row: dated when the money came back, same payee, negative.
        $refund = $this->refundRow();
        $this->assertSame($this->payment['id'], $refund->refund_of_payment_id);
        $this->assertSame('2026-10-20', $refund->paid_at->toDateString());
        $this->assertSame($this->shop->id, $refund->supplier_id);
        $this->assertSame('-120.50', (string) $refund->total);
        $line = $refund->lines()->sole();
        $this->assertSame($this->payment['lines'][0]['id'], $line->refund_of_line_id);
        $this->assertSame('other', $line->type->value);
        $this->assertSame($this->store->id, $line->store_id);
        $this->assertSame('Conveyor shaft', $line->description);
        $this->assertSame([$this->issue->id], $line->ticketIssues()->pluck('ticket_issues.id')->all());

        // Shown on its own, a refund names what it refunds.
        $this->getJson("/api/payments/{$refund->id}", $this->headers())->assertOk()
            ->assertJsonPath('data.kind', 'refund')
            ->assertJsonPath('data.refund_of.id', $this->payment['id'])
            ->assertJsonPath('data.refund_reason', 'Shaft returned, wrong size.')
            ->assertJsonPath('data.total', '-120.50')
            ->assertJsonPath('data.refunded_total', null)
            ->assertJsonPath('data.lines.0.refund_of_line_id', $this->payment['lines'][0]['id'])
            ->assertJsonPath('data.lines.0.refunded', null);
    }

    public function test_totals_everywhere_net_the_refund_in_the_month_it_came_back(): void
    {
        $this->refund([[0, '120.50']], ['refunded_at' => '2026-11-02']);

        // The list shows the refund as its own row and nets it; kind filters.
        $this->getJson('/api/payments', $this->headers())->assertOk()
            ->assertJsonPath('meta.totals.count', 2)
            ->assertJsonPath('meta.totals.total', '279.50')
            ->assertJsonPath('data.0.kind', 'refund')
            ->assertJsonPath('data.0.refund_of.id', $this->payment['id'])
            ->assertJsonPath('data.1.net_total', '279.50');
        $this->getJson('/api/payments?kind=refund', $this->headers())->assertOk()->assertJsonPath('meta.totals.total', '-120.50');
        $this->getJson('/api/payments?from=2026-10-01&to=2026-10-31', $this->headers())->assertOk()->assertJsonPath('meta.totals.total', '400.00');

        // The supplier's total paid nets it.
        $this->getJson("/api/suppliers/{$this->shop->id}", $this->headers())->assertOk()->assertJsonPath('data.total_paid', '279.50');

        // Finances: spent in October is the payment, November has the refund
        // (at the refunded line's store), which is not a payment of its own.
        $oct = $this->getJson('/api/maintenance-analytics/finances?from=2026-10-01&to=2026-10-31', $this->headers())->assertOk()->json('data.totals');
        $this->assertSame(['400.00', 1, 2], [$oct['spent'], $oct['payments'], $oct['lines']]);
        $nov = $this->getJson('/api/maintenance-analytics/finances?from=2026-11-01&to=2026-11-30', $this->headers())->assertOk()->json('data');
        $this->assertSame(['-120.50', 0, 0], [$nov['totals']['spent'], $nov['totals']['payments'], $nov['totals']['lines']]);
        $this->assertSame('-120.50', collect($nov['by_store'])->firstWhere('store_number', '03795-00001')['amount'] ?? null);

        // The ticket's cost nets it.
        $this->getJson("/api/stores/03795-00001/tickets/{$this->issue->ticket_id}/issues/{$this->issue->id}", $this->headers())->assertOk()
            ->assertJsonPath('data.costs.other', '179.50');
    }

    public function test_a_line_cannot_get_back_more_than_it_cost_across_refunds(): void
    {
        $this->refund([[0, '250']]);

        $this->refund([[0, '60']], [], 422)->assertJsonValidationErrors(['lines.0.amount' => 'At most 50.00 of this line can still be refunded.']);
        $this->refund([[0, '50'], [1, '100']]);
        $this->refund([[1, '0.01']], [], 422)->assertJsonValidationErrors('lines.0.amount');

        $this->assertSame('0.00', $this->getJson("/api/payments/{$this->payment['id']}", $this->headers())->json('data.net_total'));
    }

    public function test_what_a_refund_needs(): void
    {
        $this->refund([[0, '10']], ['reason' => ''], 422)->assertJsonValidationErrors('reason');
        $this->refund([[0, '0']], [], 422)->assertJsonValidationErrors('lines.0.amount');
        $this->refund([], [], 422)->assertJsonValidationErrors('lines');
        $this->refund([[0, '10']], ['refunded_at' => '2026-10-01'], 422)->assertJsonValidationErrors(['refunded_at' => 'The money cannot come back before it was paid (2026-10-05).']);
        $this->refund([[0, '10']], ['refunded_at' => now()->addDays(2)->toDateString()], 422)->assertJsonValidationErrors('refunded_at');

        // A line of another payment.
        $other = $this->postJson('/api/payments', [
            'paid_at' => '2026-10-05', 'supplier_id' => $this->shop->id,
            'lines' => [['type' => 'other', 'store_id' => $this->store->id, 'amount' => 5]],
        ], $this->headers())->json('data');
        $this->postJson("/api/payments/{$this->payment['id']}/refunds", [
            'refunded_at' => '2026-10-20', 'reason' => 'x',
            'lines' => [['line_id' => $other['lines'][0]['id'], 'amount' => 1]],
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors('lines.0.line_id');

        // A refund cannot be refunded; a mistaken payment has nothing to refund.
        $this->refund([[0, '10']]);
        $refundId = $this->refundRow()->id;
        $this->postJson("/api/payments/{$refundId}/refunds", [
            'refunded_at' => '2026-10-20', 'reason' => 'x', 'lines' => [['line_id' => $this->refundRow()->lines()->value('id'), 'amount' => 1]],
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors(['status' => 'A refund cannot itself be refunded.']);
        $this->postJson("/api/payments/{$other['id']}/mistaken", ['reason' => 'Wrong'], $this->headers())->assertOk();
        $this->postJson("/api/payments/{$other['id']}/refunds", [
            'refunded_at' => '2026-10-20', 'reason' => 'x', 'lines' => [['line_id' => $other['lines'][0]['id'], 'amount' => 1]],
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_undoing_a_refund_is_marking_it_mistaken_and_frees_the_payment_again(): void
    {
        $this->refund([[0, '300']]);
        $refund = $this->refundRow();
        $id = $this->payment['id'];

        // While the refund stands the payment cannot be edited or marked mistaken.
        $this->postJson("/api/payments/{$id}/mistaken", ['reason' => 'x'], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors(['status' => 'This payment has refunds: mark them as mistakes first.']);
        $this->postJson("/api/payments/{$id}/edit", [
            'expected_updated_at' => $this->payment['updated_at'], 'paid_at' => '2026-10-05', 'supplier_id' => $this->shop->id,
            'lines' => [['type' => 'other', 'store_id' => $this->store->id, 'amount' => 1]],
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors('status');

        // A refund cannot be edited.
        $this->postJson("/api/payments/{$refund->id}/edit", [
            'expected_updated_at' => $refund->updated_at->toJSON(), 'paid_at' => '2026-10-20', 'supplier_id' => $this->shop->id,
            'lines' => [['type' => 'other', 'store_id' => $this->store->id, 'amount' => 1]],
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors(['status' => 'A refund cannot be edited: mark it as a mistake and record it again.']);

        // Mark the refund mistaken: the money counts again and can be refunded again.
        $this->postJson("/api/payments/{$refund->id}/mistaken", ['reason' => 'Refund was declined.'], $this->headers())->assertOk();
        $data = $this->getJson("/api/payments/{$id}", $this->headers())->json('data');
        $this->assertSame(['0.00', '400.00'], [$data['refunded_total'], $data['net_total']]);
        $this->assertTrue($data['refunds'][0]['mistaken']);
        $this->assertSame('Refund was declined.', $data['refunds'][0]['mistaken_reason']);
        $this->getJson('/api/payments', $this->headers())->assertJsonPath('meta.totals.total', '400.00');
        $this->refund([[0, '300']]);
    }

    public function test_amend_keeps_a_refunded_line_in_step(): void
    {
        $this->refund([[0, '200']]);
        $payment = Payment::query()->findOrFail($this->payment['id']);
        $lineId = $this->payment['lines'][0]['id'];

        try {
            app(\App\Services\PaymentService::class)->amend($payment, ['lines' => [$lineId => ['amount' => '150.00']]]);
            $this->fail('A line cannot cost less than what was refunded on it.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('200.00 of this line has been refunded', collect($e->errors())->flatten()->first());
        }

        try {
            app(\App\Services\PaymentService::class)->amend($payment, ['lines' => [$lineId => ['store_id' => $this->otherStore->id]]]);
            $this->fail('A refunded line keeps its place.');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $this->assertSame('300.00', (string) PaymentLine::query()->findOrFail($lineId)->amount);
    }
}
