<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Models\Category;
use App\Models\Issue;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/maintenance-reports: the weekly, pending, technical issues, status
 * and payment reports for Nov 9-15 2026, read on Nov 19.
 */
class MaintenanceReportsTest extends TestCase
{
    use RefreshDatabase;

    private const RANGE = ['start_date' => '2026-11-09', 'end_date' => '2026-11-15'];

    private const KEY = ['X-Secret-Key' => 'test-secret', 'Accept' => 'application/json'];

    private Store $a;

    private Store $b;

    private Technician $ahmad;

    private Technician $bolt;

    /** @var array<string, TicketIssue> */
    private array $issues = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.x_secret_key' => 'test-secret']);
        $this->travelTo('2026-11-19 12:00:00');

        $mos = User::query()->create(['id' => 20, 'name' => 'Mo S', 'email' => 'mos@example.com']);
        $manager = User::query()->create(['id' => 21, 'name' => 'Store Manager', 'email' => 'sm@example.com']);

        $this->a = Store::factory()->create(['store_number' => '03795-00001']);
        $this->b = Store::factory()->create(['store_number' => '03795-00002']);
        $oven = Issue::factory()->create(['title' => 'Oven']);
        $technical = Issue::query()->forceCreate(['id' => 151, 'title' => 'POS / Technical']);

        // In the range: open, closed, cancelled.
        $this->issues['open'] = $this->issue('2026-11-10 10:00:00', $oven, ['priority' => 'high', 'created_by' => $mos->id]);
        $this->issues['closed'] = $this->issue('2026-11-11 10:00:00', $oven, ['status' => 'closed', 'resolution' => 'repaired', 'closed_at' => '2026-11-13 09:00:00', 'created_by' => $manager->id]);
        $this->issues['tech_cancelled'] = $this->issue('2026-11-12 10:00:00', $technical, ['status' => 'cancelled', 'closed_at' => '2026-11-12 15:00:00']);

        // Before the range: still outstanding, deferred this year, closed last year.
        $this->issues['tech_old'] = $this->issue('2026-10-01 10:00:00', $technical, ['status' => 'scheduled', 'priority' => 'low', 'assigned_priority' => 'urgent']);
        $this->issues['deferred'] = $this->issue('2026-03-01 10:00:00', null, ['status' => 'closed', 'resolution' => 'deferred', 'closed_at' => '2026-11-14 09:00:00']);
        $this->issues['last_year'] = $this->issue('2025-12-20 10:00:00', $oven, ['status' => 'closed', 'resolution' => 'repaired', 'closed_at' => '2025-12-22 09:00:00']);

        // Never in a report: after the range, or on a deleted ticket.
        $this->issues['after'] = $this->issue('2026-11-17 10:00:00', $oven);
        $this->issues['deleted'] = $this->issue('2026-11-10 11:00:00', $oven);
        $this->issues['deleted']->ticket->delete();

        $this->payments($mos);
    }

    /** @param  array<string, mixed>  $attributes */
    private function issue(string $at, ?Issue $catalog, array $attributes = []): TicketIssue
    {
        $issue = TicketIssue::factory()->for(Ticket::factory()->for($this->a))->create([
            'issue_id' => $catalog?->id,
            'other_title' => $catalog === null ? 'Leaking roof' : null,
        ]);
        $issue->forceFill($attributes + ['created_at' => $at])->saveQuietly();

        return $issue->refresh();
    }

    private function payments(User $mos): void
    {
        $this->ahmad = Technician::factory()->create(['name' => 'Ahmad', 'category_id' => Category::query()->firstOrCreate(['name' => 'In-house'])->id]);
        $this->bolt = Technician::factory()->create(['name' => 'Bolt Repairs', 'category_id' => Category::query()->firstOrCreate(['name' => 'Company'])->id]);

        $ahmadPay = $this->payment($this->ahmad, '2026-11-12', [
            ['type' => 'hourly', 'store_id' => $this->a->id, 'amount' => '60.00', 'hours' => '3.00', 'rate' => '20.00'],
            ['type' => 'gas', 'store_id' => $this->a->id, 'amount' => '5.00'],
            ['type' => 'other', 'store_id' => $this->a->id, 'amount' => '-10.00', 'description' => 'Money owed'],
            ['type' => 'fixed', 'store_id' => $this->b->id, 'amount' => '100.00'],
        ], createdBy: $mos->id);
        $this->clockedSession($ahmadPay->lines()->where('type', 'hourly')->sole(), '2026-11-12 08:00:00', '2026-11-12 11:00:00', travel: ['2026-11-12 08:00:00', '2026-11-12 08:30:00']);

        $boltPay = $this->payment($this->bolt, '2026-11-14', [
            ['type' => 'hourly', 'store_id' => $this->a->id, 'amount' => '50.00', 'hours' => '2.00', 'rate' => '25.00'],
            ['type' => 'inspection', 'store_id' => $this->a->id, 'amount' => '40.00'],
            ['type' => 'parts', 'store_id' => $this->a->id, 'amount' => '15.00'],
        ]);
        // $10 of Bolt's hourly money came back on the 15th.
        $refund = Payment::query()->create([
            'kind' => 'refund', 'refund_of_payment_id' => $boltPay->id, 'paid_at' => '2026-11-15',
            'technician_id' => $this->bolt->id, 'method' => 'cash', 'total' => '-10.00',
        ]);
        $refund->lines()->create([
            'type' => 'hourly', 'store_id' => $this->a->id, 'amount' => '-10.00', 'sort_order' => 0,
            'refund_of_line_id' => $boltPay->lines()->where('type', 'hourly')->value('id'),
        ]);

        // Not in the report: mistaken, after the range, paid to a supplier.
        $this->payment($this->ahmad, '2026-11-12', [['type' => 'fixed', 'store_id' => $this->a->id, 'amount' => '999.00']], mistaken: true);
        $this->payment($this->ahmad, '2026-11-16', [['type' => 'fixed', 'store_id' => $this->a->id, 'amount' => '20.00']]);
        $supplier = Payment::query()->create([
            'paid_at' => '2026-11-12', 'supplier_id' => Supplier::query()->create(['name' => 'Parts Co'])->id,
            'method' => 'cash', 'total' => '300.00',
        ]);
        $supplier->lines()->create(['type' => 'other', 'store_id' => $this->a->id, 'amount' => '300.00', 'sort_order' => 0]);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function payment(Technician $technician, string $paidAt, array $lines, bool $mistaken = false, ?int $createdBy = null): Payment
    {
        $payment = Payment::query()->create([
            'paid_at' => $paidAt,
            'technician_id' => $technician->id,
            'method' => 'cash',
            'total' => array_sum(array_map(fn ($l) => (float) $l['amount'], $lines)),
            'mistaken' => $mistaken,
            'created_by' => $createdBy,
        ]);
        foreach ($lines as $i => $l) {
            $payment->lines()->create($l + ['sort_order' => $i]);
        }

        return $payment;
    }

    /** @param  array{0: string, 1: string}  $travel */
    private function clockedSession(PaymentLine $paidBy, string $in, string $out, array $travel): void
    {
        $trip = Trip::query()->create(['technician_id' => $this->ahmad->id, 'status' => TripStatus::Open, 'planned_date' => substr($in, 0, 10)]);
        $session = TripSession::query()->create(['trip_id' => $trip->id, 'technician_id' => $this->ahmad->id, 'clock_in' => $in, 'clock_out' => $out]);
        $session->events()->create(['kind' => 'travel_start', 'at' => $travel[0]]);
        $session->events()->create(['kind' => 'travel_end', 'at' => $travel[1]]);
        $paidBy->tripSessions()->attach($session->id, ['paid_minutes' => 180]);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return $this->getJson('/api/maintenance-reports?'.http_build_query(self::RANGE), self::KEY)
            ->assertOk()->json('data');
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function ids(array $rows): array
    {
        return array_column($rows, 'ticket_issue_id');
    }

    public function test_the_issue_reports_pick_their_issues(): void
    {
        $data = $this->report();
        $i = $this->issues;

        $this->assertSame([$i['open']->id, $i['closed']->id, $i['tech_cancelled']->id], $this->ids($data['weekly_report']));
        // Every outstanding issue, the ones from before the range too; not the one after it.
        $this->assertSame([$i['tech_old']->id, $i['open']->id], $this->ids($data['pending_report']));
        // Issue 151: the cancelled one from the range, and the one outstanding since October.
        $this->assertSame([$i['tech_old']->id, $i['tech_cancelled']->id], $this->ids($data['technical_issues']));
    }

    public function test_a_row_has_the_columns_of_the_sheet(): void
    {
        [$open, $closed, $cancelled] = $this->report()['weekly_report'];

        $this->assertSame([
            'ticket_id' => $this->issues['open']->ticket_id,
            'ticket_issue_id' => $this->issues['open']->id,
            'issue_id' => $this->issues['open']->issue_id,
            'issue_date' => '2026-11-10',
            'store_number' => '03795-00001',
            'issue' => 'Oven',
            'priority' => ['value' => 'high', 'label' => 'High'],
            'submitted_by_mos' => true,
            'status' => ['value' => 'new', 'label' => 'New'],
            'resolution' => null,
            'description' => $this->issues['open']->description,
            'waiting_days' => 9,
        ], $open);

        // Waiting days stop when the issue finishes.
        $this->assertSame(2, $closed['waiting_days']);
        $this->assertSame('closed', $closed['status']['value']);
        $this->assertSame('repaired', $closed['resolution']['value']);
        $this->assertFalse($closed['submitted_by_mos']);
        $this->assertNull($cancelled['submitted_by_mos']);
        $this->assertSame(0, $cancelled['waiting_days']);

        // The assigned priority wins over the store's.
        $old = collect($this->report()['technical_issues'])->firstWhere('ticket_issue_id', $this->issues['tech_old']->id);
        $this->assertSame('urgent', $old['priority']['value']);
        $this->assertSame('scheduled', $old['status']['value']);
    }

    public function test_the_status_report_counts_and_averages(): void
    {
        $status = $this->report()['status_report'];

        // Equals: JSON reads a whole float back as an int.
        $this->assertEquals([
            'weekly_received' => [3, 3.7],
            // The repaired one (2 days) and the deferred one (258 days) closed in the range.
            'closed_weekly' => [2, 130.0],
            'new_ytd' => [1, 9.0],
            'in_review_ytd' => [0, null],
            'waiting_on_store_ytd' => [0, null],
            'inspection_ytd' => [0, null],
            'waiting_approval_ytd' => [0, null],
            'scheduled_ytd' => [1, 49.0],
            'work_done_ytd' => [0, null],
            'closed_ytd' => [2, 130.0],
            'cancelled_ytd' => [1, 0.0],
            'total_outstanding' => [2, 29.0],
        ], collect($status['statuses'])->mapWithKeys(fn ($r) => [$r['key'] => [$r['issues'], $r['avg_waiting_days']]])->all());

        $byPriority = fn (array $rows) => collect($rows)->mapWithKeys(fn ($r) => [$r['priority'] => $r['issues']])->all();
        $this->assertSame(['urgent' => 1, 'high' => 1, 'medium' => 0, 'low' => 0], $byPriority($status['outstanding_by_priority']['ytd']));
        $this->assertSame(['urgent' => 0, 'high' => 1, 'medium' => 0, 'low' => 0], $byPriority($status['outstanding_by_priority']['range']));

        $types = $status['by_issue_type'];
        $this->assertEquals(
            [['Oven', 2, 50.0, 50.0, 0.0], ['POS / Technical', 1, 0.0, 0.0, 100.0]],
            array_map(fn ($r) => [$r['issue'], $r['received'], $r['closed_pct'], $r['outstanding_pct'], $r['cancelled_pct']], $types['rows']),
        );
        $this->assertSame(3, $types['total']['received']);
        $this->assertSame(33.3, $types['total']['closed_pct']);
    }

    public function test_the_payment_report_by_store_and_by_technician(): void
    {
        $pay = $this->report()['payment_report'];

        // Equals: JSON reads a whole float back as an int.
        $this->assertEquals([
            ['03795-00001', 'Company', 2.0, '20.00', 0.0, '0.00', '0.00', '0.00', '95.00'],
            ['03795-00001', 'In-house', 3.0, '20.00', 0.5, '5.00', '-10.00', '0.00', '55.00'],
            ['03795-00002', 'In-house', 0.0, null, 0.0, '0.00', '0.00', '100.00', '100.00'],
        ], array_map(fn ($r) => [
            $r['store']['label'], $r['category']['name'], $r['hours'], $r['hourly_rate'], $r['travel_hours'],
            $r['gas'], $r['money_owed'], $r['fixed_amount'], $r['total'],
        ], $pay['by_store']));

        // Equals: JSON reads a whole float back as an int.
        $this->assertEquals([
            ['Ahmad', 'In-house', 3.0, '20.00', 0.5, '5.00', '-10.00', '100.00', '155.00'],
            ['Bolt Repairs', 'Company', 2.0, '20.00', 0.0, '0.00', '0.00', '0.00', '95.00'],
        ], array_map(fn ($r) => [
            $r['technician']['name'], $r['category']['name'], $r['hours'], $r['hourly_rate'], $r['travel_hours'],
            $r['gas'], $r['money_owed'], $r['fixed_amount'], $r['total'],
        ], $pay['by_technician']));

        $this->assertSame('250.00', $pay['total']['total']);
        $this->assertSame('40.00', $pay['total']['inspection']);
        $this->assertSame('15.00', $pay['total']['parts']);
    }

    public function test_it_needs_the_secret_key(): void
    {
        $url = '/api/maintenance-reports?'.http_build_query(self::RANGE);

        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['X-Secret-Key' => 'wrong'])->assertUnauthorized();
        $this->getJson($url, self::KEY)->assertOk();
    }

    public function test_the_dates_are_required_and_ordered(): void
    {
        $this->getJson('/api/maintenance-reports', self::KEY)
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);

        $this->getJson('/api/maintenance-reports?start_date=2026-11-15&end_date=2026-11-09', self::KEY)
            ->assertUnprocessable()->assertJsonValidationErrors(['end_date']);
    }
}
