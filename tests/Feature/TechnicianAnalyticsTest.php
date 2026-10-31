<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Models\Issue;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\StorageLocation;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The Technicians page on the new data: pay from payments (by store and by line
 * type, both adding up to what was paid), work from trip sessions.
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

    private Technician $ahmad;

    private TicketIssue $ovenAtA;

    private TicketIssue $sinkAtB;

    private PaymentLine $hourly;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();

        $this->a = Store::factory()->create(['store_number' => '03795-00001']);
        $this->b = Store::factory()->create(['store_number' => '03795-00002']);
        $this->oven = Issue::factory()->create(['title' => 'Oven']);
        $sink = Issue::factory()->create(['title' => 'Sink']);
        $this->ahmad = Technician::factory()->create(['name' => 'Ahmad']);
        Technician::factory()->create(['name' => 'Ben']);

        $this->ovenAtA = TicketIssue::factory()->for(Ticket::factory()->for($this->a))->create(['issue_id' => $this->oven->id, 'other_title' => null]);
        $this->sinkAtB = TicketIssue::factory()->for(Ticket::factory()->for($this->b))->create(['issue_id' => $sink->id, 'other_title' => null]);
        $shelf = StorageLocation::factory()->create(['name' => 'Van']);

        // Oct 5: store A 2 h at $20 + $10 parts + $3 gas = $53; store B a $100
        // fixed price; $7 of parts for the van. Paid: $160.
        $oct5 = $this->payment('2026-10-05', [
            ['type' => 'hourly', 'store_id' => $this->a->id, 'amount' => '40.00', 'hours' => '2.00', 'rate' => '20.00', 'issue' => $this->ovenAtA],
            ['type' => 'parts', 'store_id' => $this->a->id, 'amount' => '10.00', 'issue' => $this->ovenAtA],
            ['type' => 'gas', 'store_id' => $this->a->id, 'amount' => '3.00', 'issue' => $this->ovenAtA],
            ['type' => 'fixed', 'store_id' => $this->b->id, 'amount' => '100.00', 'issue' => $this->sinkAtB],
            ['type' => 'parts', 'storage_location_id' => $shelf->id, 'amount' => '7.00'],
        ]);
        $this->hourly = $oct5->lines()->where('type', 'hourly')->sole();

        // Oct 6: outside the range. A mistaken payment never counts.
        $this->payment('2026-10-06', [['type' => 'hourly', 'store_id' => $this->a->id, 'amount' => '20.00', 'issue' => $this->ovenAtA]]);
        $this->payment('2026-10-05', [['type' => 'fixed', 'store_id' => $this->a->id, 'amount' => '999.00']], mistaken: true);

        $this->clocked('2026-10-05 14:00:00', '2026-10-05 16:00:00', $this->ovenAtA);              // 2 h, in range, not paid
        $this->clocked('2026-10-05 08:00:00', '2026-10-05 09:00:00', $this->sinkAtB, paidBy: $this->hourly);
        $this->clocked('2026-10-05 17:00:00', '2026-10-05 18:00:00', $this->sinkAtB, mistaken: true);
        $this->clocked('2026-10-06 05:00:00', '2026-10-06 06:00:00', $this->ovenAtA);              // Oct 6 locally
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function payment(string $paidAt, array $lines, bool $mistaken = false): Payment
    {
        $payment = Payment::query()->create([
            'paid_at' => $paidAt,
            'technician_id' => $this->ahmad->id,
            'method' => 'cash',
            'total' => array_sum(array_map(fn ($l) => (float) $l['amount'], $lines)),
            'mistaken' => $mistaken,
        ]);

        foreach ($lines as $i => $l) {
            $issue = $l['issue'] ?? null;
            unset($l['issue']);
            $line = $payment->lines()->create($l + ['sort_order' => $i]);
            if ($issue !== null) {
                $line->ticketIssues()->attach($issue->id);
            }
        }

        return $payment;
    }

    private function clocked(string $in, string $out, TicketIssue $issue, bool $mistaken = false, ?PaymentLine $paidBy = null): TripSession
    {
        $trip = Trip::query()->create([
            'technician_id' => $this->ahmad->id,
            'status' => TripStatus::Open,
            'planned_date' => substr($in, 0, 10),
        ]);
        $stop = $trip->stops()->create(['store_id' => $issue->ticket->store_id, 'status' => 'done']);
        $stop->stopIssues()->create(['ticket_issue_id' => $issue->id, 'purpose' => 'work']);

        $session = TripSession::query()->create([
            'trip_id' => $trip->id,
            'technician_id' => $this->ahmad->id,
            'clock_in' => $in,
            'clock_out' => $out,
            'mistaken' => $mistaken,
        ]);

        $paidBy?->tripSessions()->attach($session->id, ['paid_minutes' => 60]);

        return $session;
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

        $byStore = collect($data['paid_by_store'])->mapWithKeys(fn ($r) => [($r['store_number'] ?? $r['storage_location']['name']) => $r['amount']]);
        $this->assertSame(['03795-00002' => '100.00', '03795-00001' => '53.00', 'Van' => '7.00'], $byStore->all());

        $this->assertSame([
            'hourly' => '40.00',
            'fixed' => '100.00',
            'inspection' => '0.00',
            'parts' => '17.00',
            'gas' => '3.00',
            'other' => '0.00',
        ], $data['paid_by_kind']);

        $this->assertCount(1, $data['pay_sheets']);
        $this->assertSame('2026-10-05', $data['pay_sheets'][0]['date']);
        $this->assertSame('160.00', $data['pay_sheets'][0]['amount']);
    }

    public function test_a_store_or_issue_filter_reads_the_matching_payment_lines(): void
    {
        $this->getJson($this->url("technicians/{$this->ahmad->id}/analytics", ['stores' => ['03795-00002']]), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.filtered_money', true)
            ->assertJsonPath('data.kpis.paid', '100.00')
            ->assertJsonCount(1, 'data.paid_by_store')
            ->assertJsonPath('data.paid_by_kind.fixed', '100.00');

        $this->getJson($this->url("technicians/{$this->ahmad->id}/analytics", ['issue_ids' => [$this->oven->id]]), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.kpis.paid', '53.00')
            ->assertJsonPath('data.paid_by_kind.parts', '10.00');
    }

    public function test_work_is_the_sessions_clocked_in_the_range(): void
    {
        $data = $this->getJson($this->url("technicians/{$this->ahmad->id}/analytics"), $this->headers())
            ->assertOk()->json('data');

        // The two Oct 5 sessions; not the mistaken one, not Oct 6.
        $this->assertSame(2, $data['kpis']['visits']);
        $this->assertEquals(3.0, $data['kpis']['hours']['work']);
        $this->assertSame(2, $data['kpis']['stores_served']);
        $this->assertSame(2, $data['kpis']['issues_worked']);
        $this->assertSame('2026-10-05', $data['kpis']['last_pay_date']);
        $this->assertNotNull($data['kpis']['last_worked_at']);

        $this->assertCount(2, $data['work_log']);
        // Newest first; the earlier one was paid by the hourly line.
        $this->assertSame([false, true], array_column($data['work_log'], 'paid'));
        $this->assertSame(['session', 'session'], array_column($data['work_log'], 'source'));
        $this->assertSame([['03795-00001'], ['03795-00002']], array_column($data['work_log'], 'stores'));
    }

    public function test_a_store_filter_keeps_only_the_sessions_there(): void
    {
        $data = $this->getJson($this->url("technicians/{$this->ahmad->id}/analytics", ['stores' => ['03795-00001']]), $this->headers())
            ->assertOk()->json('data');

        $this->assertSame(1, $data['kpis']['visits']);
        $this->assertEquals(2.0, $data['kpis']['hours']['work']);
        $this->assertSame(['title' => 'Oven', 'visits' => 1], array_intersect_key($data['work_by_issue'][0], ['title' => 0, 'visits' => 0]));
    }

    public function test_the_overview_lists_every_technician(): void
    {
        $data = $this->getJson($this->url('technician-analytics'), $this->headers())->assertOk()->json('data');

        $rows = collect($data['technicians'])->keyBy('name');
        $this->assertSame('160.00', $rows['Ahmad']['paid']);
        $this->assertSame(2, $rows['Ahmad']['visits']);
        $this->assertSame(2, $rows['Ahmad']['stores_served']);
        $this->assertSame('2026-10-05', $rows['Ahmad']['last_pay_date']);
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
