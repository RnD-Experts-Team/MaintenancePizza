<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\PaymentMethod;
use App\Models\PaymentRevision;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\User;
use App\Services\PaymentHistory\PaymentHistoryImport;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Loading the reviewed R&M payments history (PaymentHistoryImport) and the
 * in-place correction it uses (PaymentService::amend).
 */
class PaymentHistoryImportTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $otherStore;

    private Technician $tech;

    /** A payment that existed when the review was made (the "limit"). */
    private Payment $old;

    private string $planPath;

    protected function setUp(): void
    {
        parent::setUp();

        Auth::setUser(User::query()->create(['id' => 9, 'name' => 'Dana Whitfield', 'email' => 'dana@example.com']));

        $this->store = Store::factory()->create(['store_number' => '03795-00001']);
        $this->otherStore = Store::factory()->create(['store_number' => '03795-00002']);
        $this->tech = Technician::factory()->create(['name' => 'IMC']);

        $this->old = $this->service()->create([
            'paid_at' => '2026-09-01',
            'technician_id' => $this->tech->id,
            'lines' => [['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => '150.00']],
        ], []);

        $this->planPath = tempnam(sys_get_temp_dir(), 'plan');
    }

    protected function tearDown(): void
    {
        @unlink($this->planPath);
        parent::tearDown();
    }

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function plan(array $operations, array $extra = []): array
    {
        return [
            'version' => 1,
            'last_touchable_payment_id' => $this->old->id,
            'payees' => [['key' => 'home depot', 'kind' => 'supplier', 'name' => 'Home Depot']],
            'operations' => $operations,
        ] + $extra;
    }

    /** @return array<string, mixed> */
    private function createOp(int $row, string $amount, array $line = [], array $op = []): array
    {
        return $op + [
            'op' => 'create',
            'sheet_rows' => [$row],
            'paid_at' => '2026-03-02',
            'payee' => ['new' => 'home depot'],
            'payment_method' => 'Card',
            'note' => "Imported from the R&M sheet, row {$row}.",
            'lines' => [$line + ['type' => 'other', 'store_id' => $this->store->id, 'description' => 'Door lock', 'amount' => $amount]],
        ];
    }

    /** @return array<string, string> how the review saw the payment */
    private function seen(Payment $payment): array
    {
        return [(string) $payment->id => (string) DB::table('payments')->where('id', $payment->id)->value('updated_at')];
    }

    private function issueAt(Store $store): TicketIssue
    {
        $ticket = Ticket::factory()->create(['store_id' => $store->id]);

        return TicketIssue::factory()->create(['ticket_id' => $ticket->id]);
    }

    /** @return array<string, mixed> */
    private function runImport(array $plan, bool $dryRun = false): array
    {
        return app(PaymentHistoryImport::class)->run($plan, $dryRun);
    }

    // ------------------------------------------------------------------ Import

    public function test_create_adds_the_payment_its_new_payee_method_ticket_and_note_and_records_the_row(): void
    {
        $issue = $this->issueAt($this->store);

        $result = $this->runImport($this->plan(
            [$this->createOp(1010, '115.00', ['ticket_issue_ids' => [$issue->id]])],
            ['expected' => ['create_total' => '115.00', 'sheet_rows' => 1]],
        ));

        $this->assertTrue($result['committed'], json_encode($result));
        $payment = Payment::query()->whereKey($result['operations'][0]['payment_ids'][0])->sole();
        $this->assertSame('2026-03-02', $payment->paid_at->toDateString());
        $this->assertSame('115.00', (string) $payment->total);
        $this->assertSame('Home Depot', Supplier::query()->findOrFail($payment->supplier_id)->name);
        $this->assertSame(PaymentMethod::query()->where('name', 'Card')->value('id'), $payment->payment_method_id);
        $this->assertSame([$issue->id], $payment->lines->first()->ticketIssues()->pluck('ticket_issues.id')->all());
        $this->assertSame('Imported from the R&M sheet, row 1010.', $payment->notes()->value('body'));
        $this->assertSame($payment->id, (int) DB::table('legacy_conversion_map')->where('legacy_table', 'rm_sheet_row')->where('legacy_id', 1010)->value('new_id'));
        $this->assertSame(1, $result['counts']['payees_created']);
    }

    public function test_a_dry_run_reports_and_keeps_nothing(): void
    {
        $result = $this->runImport($this->plan([$this->createOp(1, '10.00')], ['expected' => ['create_total' => '10.00']]), dryRun: true);

        $this->assertFalse($result['committed']);
        $this->assertSame(1, $result['counts']['created']);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(0, Supplier::query()->count());
        $this->assertSame(0, DB::table('legacy_conversion_map')->count());
    }

    public function test_running_the_same_plan_twice_imports_each_row_once(): void
    {
        $plan = $this->plan([$this->createOp(1, '10.00')], ['expected' => ['create_total' => '10.00']]);

        $this->assertTrue($this->runImport($plan)['committed']);
        $second = $this->runImport($plan);

        $this->assertTrue($second['committed']);
        $this->assertSame(1, $second['counts']['already_done']);
        $this->assertSame(0, $second['counts']['created']);
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(1, Supplier::query()->count());
    }

    public function test_a_plan_naming_a_payment_after_the_limit_is_refused_before_anything_is_written(): void
    {
        $later = $this->service()->create([
            'paid_at' => '2026-11-15',
            'technician_id' => $this->tech->id,
            'lines' => [['type' => 'fixed', 'store_id' => $this->store->id, 'amount' => '99.00']],
        ], []);

        foreach ([
            ['op' => 'mistaken', 'payment_id' => $later->id, 'reviewed_updated_at' => $this->seen($later)],
            ['op' => 'amend', 'payment_id' => $later->id, 'reviewed_updated_at' => $this->seen($later), 'changes' => ['payment_method' => 'Card']],
            ['op' => 'keep', 'payment_ids' => [$later->id], 'reviewed_updated_at' => $this->seen($later), 'sheet_rows' => [5]],
        ] as $op) {
            try {
                $this->runImport($this->plan([$this->createOp(1, '10.00'), $op]));
                $this->fail('A plan touching a later payment must be refused.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString("only payments up to #{$this->old->id}", $e->getMessage());
            }
        }

        $this->assertSame(2, Payment::query()->count());
        $this->assertFalse($later->fresh()->mistaken);
        $this->assertNull($later->fresh()->payment_method_id);
    }

    public function test_a_payment_entered_after_the_review_with_the_same_amount_holds_the_row_and_blocks_the_commit(): void
    {
        $later = $this->service()->create([
            'paid_at' => '2026-03-04',
            'technician_id' => $this->tech->id,
            'lines' => [['type' => 'other', 'store_id' => $this->store->id, 'amount' => '42.50']],
        ], []);

        $result = $this->runImport($this->plan([$this->createOp(1, '42.50'), $this->createOp(2, '10.00')], ['expected' => ['create_total' => '52.50']]));

        $this->assertFalse($result['committed']);
        $this->assertSame('held', $result['operations'][0]['status']);
        $this->assertStringContainsString("#{$later->id}", $result['operations'][0]['message']);
        $this->assertSame(2, Payment::query()->count());

        // Once someone has checked it is a different payment, it goes in.
        $result = $this->runImport($this->plan(
            [$this->createOp(1, '42.50', [], ['not_a_late_entry' => true]), $this->createOp(2, '10.00')],
            ['expected' => ['create_total' => '52.50']],
        ));
        $this->assertTrue($result['committed'], json_encode($result['checks']));
        $this->assertTrue(collect($result['checks'])->firstWhere('name', "Payments after #{$this->old->id} untouched")['ok']);
    }

    public function test_two_sheet_rows_with_the_same_amount_do_not_hold_each_other(): void
    {
        $result = $this->runImport($this->plan(
            [$this->createOp(1, '65.00'), $this->createOp(2, '65.00')],
            ['expected' => ['create_total' => '130.00']],
        ));

        $this->assertTrue($result['committed'], json_encode($result['operations']));
        $this->assertSame(2, $result['counts']['created']);
    }

    public function test_one_failing_operation_rolls_the_whole_run_back(): void
    {
        $elsewhere = $this->issueAt($this->otherStore);

        $result = $this->runImport($this->plan([
            $this->createOp(1, '10.00'),
            $this->createOp(2, '20.00', ['ticket_issue_ids' => [$elsewhere->id]]),
        ]));

        $this->assertFalse($result['committed']);
        $this->assertSame('error', $result['operations'][1]['status']);
        $this->assertStringContainsString("is not at this line's store", $result['operations'][1]['message']);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(0, DB::table('legacy_conversion_map')->count());
    }

    public function test_amend_keep_mistaken_and_skip(): void
    {
        $issue = $this->issueAt($this->otherStore);
        $other = $this->service()->create([
            'paid_at' => '2026-08-01',
            'technician_id' => $this->tech->id,
            'lines' => [['type' => 'other', 'store_id' => $this->store->id, 'amount' => '30.00']],
        ], []);
        $line = $this->old->lines()->sole();

        $plan = $this->plan([
            ['op' => 'amend', 'payment_id' => $this->old->id, 'reviewed_updated_at' => $this->seen($this->old), 'sheet_rows' => [7], 'note' => 'Matched to R&M sheet row 7.', 'changes' => [
                'payment_method' => 'Check',
                'lines' => [(string) $line->id => ['store_id' => $this->otherStore->id, 'add_ticket_issue_ids' => [$issue->id]]],
            ]],
            ['op' => 'keep', 'payment_ids' => [$other->id], 'reviewed_updated_at' => $this->seen($other), 'sheet_rows' => [8]],
            ['op' => 'mistaken', 'payment_id' => $other->id, 'reviewed_updated_at' => $this->seen($other), 'reason' => 'Duplicate of an R&M row.'],
            ['op' => 'skip', 'sheet_rows' => [9], 'reason' => 'Refund taken off row 8.'],
        ]);
        $plan['last_touchable_payment_id'] = $other->id;

        $result = $this->runImport($plan);

        $this->assertTrue($result['committed'], json_encode($result));
        $old = $this->old->fresh();
        $this->assertSame(PaymentMethod::query()->where('name', 'Check')->value('id'), $old->payment_method_id);
        $this->assertSame($this->otherStore->id, $line->fresh()->store_id);
        $this->assertSame([$issue->id], $line->fresh()->ticketIssues()->pluck('ticket_issues.id')->all());
        $this->assertSame('150.00', (string) $old->total);
        $this->assertSame(1, PaymentRevision::query()->where('payment_id', $old->id)->count());
        $this->assertTrue($other->fresh()->mistaken);
        $this->assertSame(
            [7 => 'payments', 8 => 'payments', 9 => 'skipped'],
            DB::table('legacy_conversion_map')->where('legacy_table', 'rm_sheet_row')->orderBy('legacy_id')->pluck('new_table', 'legacy_id')->all(),
        );
    }

    public function test_a_payment_changed_after_the_review_stops_the_run_and_a_second_run_still_passes(): void
    {
        $seen = $this->seen($this->old);
        $plan = $this->plan([
            ['op' => 'keep', 'payment_ids' => [$this->old->id], 'reviewed_updated_at' => $seen, 'sheet_rows' => [3]],
            ['op' => 'amend', 'payment_id' => $this->old->id, 'reviewed_updated_at' => $seen, 'changes' => ['payment_method' => 'Card']],
        ]);

        // Someone edits it after the review: nothing is written.
        DB::table('payments')->where('id', $this->old->id)->update(['updated_at' => '2030-01-01 00:00:00']);
        $result = $this->runImport($plan);

        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('changed after the review', $result['operations'][0]['message']);
        $this->assertNull($this->old->fresh()->payment_method_id);

        // As the review saw it: it goes in, and running it again still passes
        // although the amend moved the payment on.
        DB::table('payments')->where('id', $this->old->id)->update(['updated_at' => $seen[(string) $this->old->id]]);
        $this->assertTrue($this->runImport($plan)['committed']);
        $again = $this->runImport($plan);

        $this->assertTrue($again['committed'], json_encode($again['operations']));
        $this->assertSame(PaymentMethod::query()->where('name', 'Card')->value('id'), $this->old->fresh()->payment_method_id);
    }

    public function test_keeping_a_payment_marked_mistaken_after_the_review_is_refused(): void
    {
        $this->service()->markMistaken($this->old, 'Wrong.');

        $result = $this->runImport($this->plan([
            ['op' => 'keep', 'payment_ids' => [$this->old->id], 'reviewed_updated_at' => $this->seen($this->old), 'sheet_rows' => [3]],
        ]));

        $this->assertFalse($result['committed']);
        $this->assertStringContainsString('marked mistaken after the review', $result['operations'][0]['message']);
    }

    public function test_a_plan_without_the_reviewed_state_of_a_payment_is_refused(): void
    {
        $this->expectExceptionMessage('without saying how it looked in the review');
        $this->runImport($this->plan([['op' => 'mistaken', 'payment_id' => $this->old->id]]));
    }

    // ---------------------------------------------------- PaymentService::amend

    public function test_amend_changes_a_typed_amount_and_the_total_but_not_an_hourly_payee(): void
    {
        $line = $this->old->lines()->sole();
        $this->service()->amend($this->old, ['lines' => [$line->id => ['amount' => '175.25']]]);
        $this->assertSame('175.25', (string) $this->old->fresh()->total);

        $supplier = Supplier::query()->create(['name' => 'Lowes']);
        $this->service()->amend($this->old, ['technician_id' => null, 'supplier_id' => $supplier->id]);
        $this->assertSame($supplier->id, $this->old->fresh()->supplier_id);

        $hourly = $this->service()->create([
            'paid_at' => '2026-09-02',
            'technician_id' => $this->tech->id,
            'lines' => [['type' => 'hourly', 'store_id' => $this->store->id, 'hours' => 2, 'rate' => 20]],
        ], []);

        $this->expectException(ValidationException::class);
        $this->service()->amend($hourly, ['technician_id' => null, 'supplier_id' => $supplier->id]);
    }

    public function test_amend_refuses_the_amount_of_an_hourly_line_and_a_ticket_at_another_store(): void
    {
        $hourly = $this->service()->create([
            'paid_at' => '2026-09-02',
            'technician_id' => $this->tech->id,
            'lines' => [['type' => 'hourly', 'store_id' => $this->store->id, 'hours' => 2, 'rate' => 20]],
        ], []);
        $line = $hourly->lines()->sole();

        try {
            $this->service()->amend($hourly, ['lines' => [$line->id => ['amount' => '1.00']]]);
            $this->fail('An hourly amount is worked out, not typed.');
        } catch (ValidationException) {
        }

        try {
            $this->service()->amend($hourly, ['lines' => [$line->id => ['add_ticket_issue_ids' => [$this->issueAt($this->otherStore)->id]]]]);
            $this->fail('A ticket at another store cannot be paid by this line.');
        } catch (ValidationException) {
        }

        $this->assertSame('40.00', (string) $hourly->fresh()->total);
        $this->assertSame(0, $line->fresh()->ticketIssues()->count());
        $this->assertInstanceOf(PaymentLine::class, $line->fresh());
    }
}
