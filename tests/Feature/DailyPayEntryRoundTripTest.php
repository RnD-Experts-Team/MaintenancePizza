<?php

namespace Tests\Feature;

use App\Models\DailyPayEntry;
use App\Models\DailyPayEntryRevision;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Services\DailyPayEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Create / edit / revision behaviour for the three-level pay sheet:
 * entry (the date) → payment (one payee) → line (one store).
 */
class DailyPayEntryRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private Technician $technician;

    private Store $storeA;

    private Store $storeB;

    private TicketIssue $issueA;

    private TicketIssue $issueB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician = Technician::factory()->create();
        $this->storeA = Store::factory()->create();
        $this->storeB = Store::factory()->create();

        $this->issueA = TicketIssue::factory()->for(Ticket::factory()->create(['store_id' => $this->storeA->id]))->create();
        $this->issueB = TicketIssue::factory()->for(Ticket::factory()->create(['store_id' => $this->storeB->id]))->create();

        $this->technician->ticketIssues()->attach([$this->issueA->id, $this->issueB->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'date' => '2026-09-10',
            'payments' => [[
                'technician_id' => $this->technician->id,
                'hourly_payment_rate' => 18.0000,
                'gas' => 20.00,
                'money_owed' => 5.00,
                'notes' => [['body' => 'paid by transfer', 'type' => null]],
                'lines' => [
                    [
                        'store_id' => $this->storeA->id,
                        'total_working_hours' => 5.00,
                        'ticket_issue_ids' => [$this->issueA->id],
                        'notes' => [['body' => 'morning at store A']],
                    ],
                    [
                        'store_id' => $this->storeB->id,
                        'total_working_hours' => 3.00,
                        'ticket_issue_ids' => [$this->issueB->id],
                    ],
                ],
            ]],
        ], $overrides);
    }

    private function service(): DailyPayEntryService
    {
        return app(DailyPayEntryService::class);
    }

    /** One payment, one payee, two stores — the thing the old shape could not express. */
    public function test_one_payment_covers_several_stores(): void
    {
        $result = $this->service()->create($this->payload());

        $this->assertCount(1, $result['payments']);

        $payment = $result['payments'][0];
        $this->assertSame($this->technician->id, $payment['technician_id']);
        $this->assertCount(2, $payment['lines']);

        $this->assertEqualsCanonicalizing(
            [$this->storeA->id, $this->storeB->id],
            array_column($payment['lines'], 'store_id')
        );

        // Notes land at both levels.
        $this->assertSame('paid by transfer', $payment['notes'][0]['body']);
        $this->assertSame('morning at store A', $payment['lines'][0]['notes'][0]['body']);
    }

    /**
     * Labour is paid on work + travel + parts-run at the rate; break time is
     * tracked but unpaid. money_owed is an additive extra, not the total.
     */
    public function test_the_payment_total_follows_the_stated_formula(): void
    {
        $result = $this->service()->create($this->payload([
            'payments' => [[
                'gas' => 10.00,
                'money_owed' => 5.00,
                'lines' => [
                    ['total_working_hours' => 5.00, 'travel_time' => 1.00, 'total_break_time' => 0.50, 'gas' => 20.00],
                    ['total_working_hours' => 3.00],
                ],
            ]],
        ]));

        $payment = $result['payments'][0];

        // Line A: (5 work + 1 travel) x 18 = 108, + 20 gas = 128. Break unpaid.
        $this->assertSame('128.00', (string) $payment['lines'][0]['line_total']);
        // Line B: 3 x 18 = 54.
        $this->assertSame('54.00', (string) $payment['lines'][1]['line_total']);
        $this->assertSame('182.00', (string) $payment['lines_total']);
        // Plus the payment's own gas and the extra owed.
        $this->assertSame('197.00', (string) $payment['total_amount']);
    }

    public function test_a_lump_sum_replaces_hours_times_rate_rather_than_adding_to_it(): void
    {
        $result = $this->service()->create($this->payload([
            'payments' => [[
                'gas' => null,
                'money_owed' => null,
                'lines' => [
                    ['total_working_hours' => 5.00, 'lump_sum' => 400.00],
                    ['total_working_hours' => 3.00],
                ],
            ]],
        ]));

        $payment = $result['payments'][0];

        $this->assertSame('400.00', (string) $payment['lines'][0]['line_total']);
        $this->assertSame('454.00', (string) $payment['total_amount']);
    }

    public function test_a_payment_level_lump_sum_replaces_all_of_its_lines_labour(): void
    {
        $result = $this->service()->create($this->payload([
            'payments' => [[
                'lump_sum' => 500.00,
                'gas' => 10.00,
                'money_owed' => 5.00,
                'lines' => [
                    ['total_working_hours' => 5.00, 'gas' => 20.00],
                    ['total_working_hours' => 3.00],
                ],
            ]],
        ]));

        // 500 flat + 20 line gas + 10 payment gas + 5 owed. The 8 hours of
        // labour the lines worked out are replaced, not added.
        $this->assertSame('535.00', (string) $result['payments'][0]['total_amount']);
    }

    public function test_edit_snapshots_the_prior_state_and_replaces_the_payments(): void
    {
        $service = $this->service();
        $created = $service->create($this->payload());
        $entry = DailyPayEntry::findOrFail($created['id']);
        $originalPaymentId = $created['payments'][0]['id'];

        $edited = $service->edit($entry, $this->payload([
            'payments' => [['lines' => [['total_working_hours' => 6.25]]]],
        ]));

        $this->assertCount(1, $edited['revisions']);

        $revision = $edited['revisions'][0];
        $this->assertSame(2, $revision['schema_version']);
        $this->assertSame('5.00', (string) $revision['snapshot']['payments'][0]['lines'][0]['total_working_hours']);

        $this->assertSame('6.25', (string) $edited['payments'][0]['lines'][0]['total_working_hours']);
        $this->assertNotSame($originalPaymentId, $edited['payments'][0]['id']);

        // The replaced rows are gone, not orphaned.
        $this->assertDatabaseCount('daily_pay_payments', 1);
        $this->assertDatabaseCount('daily_pay_lines', 2);
    }

    /**
     * Deleting the payments must cascade all the way down, or a stale claim
     * would keep paying for work the new payment no longer covers.
     */
    public function test_editing_leaves_no_orphaned_lines_issue_links_or_claims(): void
    {
        $service = $this->service();
        $created = $service->create($this->payload());
        $entry = DailyPayEntry::findOrFail($created['id']);

        $service->edit($entry, $this->payload([
            'payments' => [['lines' => [['store_id' => $this->storeA->id, 'ticket_issue_ids' => [$this->issueA->id]]]]],
        ]));

        $this->assertDatabaseCount('daily_pay_payments', 1);
        $this->assertSame(
            \App\Models\DailyPayLine::count(),
            \Illuminate\Support\Facades\DB::table('daily_pay_line_ticket_issue')
                ->distinct()->count('daily_pay_line_id'),
            'Issue links exist for lines that no longer do.'
        );
    }

    public function test_a_payee_may_only_appear_once_per_pay_sheet(): void
    {
        $request = new \App\Http\Requests\StoreDailyPayEntryRequest();
        $validator = \Illuminate\Support\Facades\Validator::make([
            'date' => '2026-09-10',
            'payments' => [
                ['technician_id' => $this->technician->id, 'lines' => [['store_id' => $this->storeA->id]]],
                ['technician_id' => $this->technician->id, 'lines' => [['store_id' => $this->storeB->id]]],
            ],
        ], $request->rules(), $request->messages());

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('only appear once', $validator->errors()->first('payments.0.technician_id'));
    }

    public function test_list_filters_by_payee_and_by_store(): void
    {
        $service = $this->service();
        $created = $service->create($this->payload());

        $this->assertSame(
            [$created['id']],
            $service->list(['technician_ids' => [$this->technician->id]])->getCollection()->pluck('id')->all()
        );
        $this->assertSame(
            [$created['id']],
            $service->list(['store_ids' => [$this->storeB->id]])->getCollection()->pluck('id')->all()
        );
        $this->assertCount(0, $service->list(['technician_ids' => [$this->technician->id + 999]])->getCollection());
    }

    /**
     * Snapshots are never rewritten, so a pay sheet edited before the payments
     * level must still read back as the shape it was saved in.
     */
    public function test_a_version_one_snapshot_is_still_readable(): void
    {
        $created = $this->service()->create($this->payload());
        $entry = DailyPayEntry::findOrFail($created['id']);

        DailyPayEntryRevision::create([
            'daily_pay_entry_id' => $entry->id,
            'snapshot' => ['date' => '2026-09-01', 'lines' => [['total_working_hours' => '7.00']]],
            'schema_version' => 1,
            'edited_by' => null,
        ]);

        $shown = $this->service()->show($entry->refresh());
        $v1 = collect($shown['revisions'])->firstWhere('schema_version', 1);

        $this->assertNotNull($v1);
        $this->assertSame('7.00', $v1['snapshot']['lines'][0]['total_working_hours']);
    }
}
