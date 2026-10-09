<?php

namespace Tests\Feature;

use App\Models\AttendanceEntry;
use App\Models\AttendanceEvent;
use App\Models\DailyPayEntry;
use App\Models\DailyPayLine;
use App\Models\DailyPayPayment;
use App\Models\Issue;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The Technicians page: pay from the daily pay sheets (by store and by kind,
 * both adding up to what was paid), work from attendance.
 */
class TechnicianAnalyticsTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    /** New York's Oct 1-5: pay compares days, work compares the instants. */
    private const RANGE = [
        'from' => '2026-10-01T04:00:00Z',
        'to' => '2026-10-06T04:00:00Z',
        'date_from' => '2026-10-01',
        'date_to' => '2026-10-05',
    ];

    private Store $a;

    private Store $b;

    private Issue $oven;

    private Issue $sink;

    private Technician $ahmad;

    private TicketIssue $ovenAtA;

    private TicketIssue $sinkAtB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();

        $this->a = Store::factory()->create(['store_number' => '03795-00001']);
        $this->b = Store::factory()->create(['store_number' => '03795-00002']);
        $this->oven = Issue::factory()->create(['title' => 'Oven']);
        $this->sink = Issue::factory()->create(['title' => 'Sink']);
        $this->ahmad = Technician::factory()->create(['name' => 'Ahmad']);
        Technician::factory()->create(['name' => 'Ben']);

        $this->ovenAtA = TicketIssue::factory()->for(Ticket::factory()->for($this->a))->create(['issue_id' => $this->oven->id, 'other_title' => null]);
        $this->sinkAtB = TicketIssue::factory()->for(Ticket::factory()->for($this->b))->create(['issue_id' => $this->sink->id, 'other_title' => null]);

        // Oct 5: store A, 2 h at $20 + $3 gas + $10 parts = $53; store B, a
        // $100 lump sum; on the payment, $5 gas and $2 of parts no store
        // carries. Paid: 40 + 100 + 3 + 5 + 12 = $160.
        $this->sheet('2026-10-05', [
            ['store' => $this->a, 'hours' => '2.00', 'gas' => '3.00', 'parts' => '10.00', 'lump' => null, 'total' => '53.00', 'issue' => $this->ovenAtA],
            ['store' => $this->b, 'hours' => '0.00', 'gas' => '0.00', 'parts' => '0.00', 'lump' => '100.00', 'total' => '100.00', 'issue' => $this->sinkAtB],
        ], paymentGas: '5.00', paymentParts: '12.00', total: '160.00');

        // Oct 6: outside the range.
        $this->sheet('2026-10-06', [
            ['store' => $this->a, 'hours' => '1.00', 'gas' => '0.00', 'parts' => '0.00', 'lump' => null, 'total' => '20.00', 'issue' => $this->ovenAtA],
        ], paymentGas: '0.00', paymentParts: '0.00', total: '20.00');

        $this->visit('2026-10-05 14:00:00', '2026-10-05 16:00:00', $this->ovenAtA);          // 2 h, in range
        $this->visit('2026-10-05 17:00:00', '2026-10-05 18:00:00', $this->sinkAtB, mistaken: true);
        $this->visit('2026-10-06 05:00:00', '2026-10-06 06:00:00', $this->ovenAtA);          // Oct 6 locally
    }

    /**
     * @param  array<int, array{store: Store, hours: string, gas: string, parts: string, lump: ?string, total: string, issue: TicketIssue}>  $lines
     */
    private function sheet(string $date, array $lines, string $paymentGas, string $paymentParts, string $total): void
    {
        $entry = DailyPayEntry::query()->create(['date' => $date]);
        $payment = DailyPayPayment::query()->create([
            'daily_pay_entry_id' => $entry->id,
            'technician_id' => $this->ahmad->id,
            'hourly_payment_rate' => '20.00',
            'gas' => $paymentGas,
            'money_owed' => '0.00',
            'frozen_reimbursable_parts' => $paymentParts,
            'lines_total' => '0.00',
            'total_amount' => $total,
        ]);

        $linesTotal = '0.00';
        foreach ($lines as $l) {
            $line = DailyPayLine::query()->create([
                'daily_pay_entry_id' => $entry->id,
                'daily_pay_payment_id' => $payment->id,
                'technician_id' => $this->ahmad->id,
                'store_id' => $l['store']->id,
                'total_working_hours' => $l['hours'],
                'travel_time' => '0.00',
                'parts_run_time' => '0.00',
                'total_break_time' => '0.00',
                'gas' => $l['gas'],
                'money_owed' => '0.00',
                'lump_sum' => $l['lump'],
                'frozen_reimbursable_parts' => $l['parts'],
                'line_total' => $l['total'],
            ]);
            $line->ticketIssues()->attach($l['issue']->id);
            $linesTotal = bcadd($linesTotal, $l['total'], 2);
        }

        $payment->update(['lines_total' => $linesTotal]);
    }

    private function visit(string $in, string $out, TicketIssue $issue, bool $mistaken = false): void
    {
        $entry = AttendanceEntry::factory()->create([
            'technician_id' => $this->ahmad->id,
            'start_clock' => $in,
            'end_clock' => $out,
            'mistaken' => $mistaken,
        ]);
        AttendanceEvent::query()->create(['attendance_entry_id' => $entry->id, 'kind' => 'clock_in', 'at' => $in]);
        AttendanceEvent::query()->create(['attendance_entry_id' => $entry->id, 'kind' => 'clock_out', 'at' => $out]);
        $entry->ticketIssues()->attach($issue->id);
    }

    private function url(string $path, array $query = []): string
    {
        return "/api/{$path}?".http_build_query($query + self::RANGE);
    }

    public function test_paid_by_store_and_by_kind_each_add_up_to_what_was_paid(): void
    {
        $data = $this->getJson($this->url("technicians/{$this->ahmad->id}/analytics"), $this->headers())
            ->assertOk()->json('data');

        $this->assertSame('160.00', $data['kpis']['paid']);
        $this->assertSame('180.00', $data['kpis']['paid_all_time']);
        $this->assertSame(1, $data['kpis']['pay_days']);

        $byStore = collect($data['paid_by_store'])->mapWithKeys(fn ($r) => [($r['payment_level'] ? 'payment' : $r['store_number']) => $r['amount']]);
        $this->assertSame(['03795-00002' => '100.00', '03795-00001' => '53.00', 'payment' => '7.00'], $byStore->all());

        $this->assertSame([
            'hourly_labour' => '40.00',
            'lump_sums' => '100.00',
            'gas' => '8.00',
            'money_owed' => '0.00',
            'parts_reimbursed' => '12.00',
        ], $data['paid_by_kind']);

        $this->assertCount(1, $data['pay_sheets']);
        $this->assertSame('2026-10-05', $data['pay_sheets'][0]['date']);
    }

    public function test_a_store_or_issue_filter_reads_the_matching_pay_lines(): void
    {
        $this->getJson($this->url("technicians/{$this->ahmad->id}/analytics", ['stores' => ['03795-00002']]), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.filtered_money', true)
            ->assertJsonPath('data.kpis.paid', '100.00')
            ->assertJsonCount(1, 'data.paid_by_store')
            ->assertJsonPath('data.paid_by_kind.lump_sums', '100.00');

        $this->getJson($this->url("technicians/{$this->ahmad->id}/analytics", ['issue_ids' => [$this->oven->id]]), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.kpis.paid', '53.00')
            ->assertJsonPath('data.paid_by_kind.parts_reimbursed', '10.00');
    }

    public function test_work_counts_visits_in_the_range_and_never_mistaken_ones(): void
    {
        $data = $this->getJson($this->url("technicians/{$this->ahmad->id}/analytics"), $this->headers())
            ->assertOk()->json('data');

        $this->assertSame(1, $data['kpis']['visits']);
        $this->assertEquals(2.0, $data['kpis']['hours']['work']);
        $this->assertSame(1, $data['kpis']['stores_served']);
        $this->assertSame('Oven', $data['work_by_issue'][0]['title']);
        $this->assertSame('03795-00001', $data['work_by_store'][0]['store']);
        $this->assertCount(1, $data['work_log']);
        $this->assertFalse($data['work_log'][0]['paid']);
    }

    public function test_the_overview_lists_every_technician(): void
    {
        $data = $this->getJson($this->url('technician-analytics'), $this->headers())->assertOk()->json('data');

        $rows = collect($data['technicians'])->keyBy('name');
        $this->assertSame('160.00', $rows['Ahmad']['paid']);
        $this->assertSame(1, $rows['Ahmad']['visits']);
        $this->assertSame('0.00', $rows['Ben']['paid']);
        $this->assertSame(0, $rows['Ben']['visits']);
        $this->assertSame('160.00', $data['totals']['paid']);
        $this->assertSame(1, $data['totals']['technicians_worked']);
    }

    public function test_ranges_are_validated(): void
    {
        $this->getJson('/api/technician-analytics?'.http_build_query(['from' => self::RANGE['from'], 'to' => self::RANGE['to']]), $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['date_from', 'date_to']);

        $this->getJson($this->url('technician-analytics', ['from' => '2025-01-01T00:00:00Z']), $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }
}
