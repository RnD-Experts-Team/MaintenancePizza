<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Issue;
use App\Models\IssueApproval;
use App\Models\IssueInspection;
use App\Models\Part;
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
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * GET maintenance-analytics/finances (docs §6): where the money went, summed
 * from payment lines, mistaken payments out, filters cutting inside payments.
 */
class FinanceAnalyticsTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    private Technician $ann;

    private Technician $bob;

    private Supplier $depot;

    private Category $inHouse;

    private Category $company;

    private TicketIssue $ti1;

    private TicketIssue $ti2;

    private TicketIssue $ti3;

    private Issue $oven;

    private StorageLocation $shelf;

    private int $annLine;

    private int $mistakenLine;

    private const OCTOBER = ['from' => '2026-10-01', 'to' => '2026-10-31'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();

        $this->a = Store::factory()->create(['store_number' => '03795-00001']);
        $this->b = Store::factory()->create(['store_number' => '03795-00002']);
        $this->inHouse = Category::query()->where('name', 'In-house')->firstOrFail();
        $this->company = Category::query()->where('name', 'Company')->firstOrFail();
        $this->ann = Technician::factory()->create(['name' => 'Ann', 'category_id' => $this->inHouse->id]);
        $this->bob = Technician::factory()->create(['name' => 'Bob', 'category_id' => $this->company->id]);
        $this->depot = Supplier::query()->create(['name' => 'Home Depot']);
        $this->shelf = StorageLocation::factory()->create();

        $this->oven = Issue::factory()->create(['title' => 'Oven']);
        $fryer = Issue::factory()->create(['title' => 'Fryer']);
        $this->ti1 = $this->issueAt($this->a, $this->oven);
        $this->ti2 = $this->issueAt($this->a, $this->oven);
        $this->ti3 = $this->issueAt($this->b, $fryer);

        // October: Ann 100 hourly at A (ti1) + 20 gas at B (ti3); Bob 300 fixed
        // at A shared by ti1 and ti2; Home Depot 50 of parts for the shelf.
        [, [$this->annLine]] = $this->pay(['technician_id' => $this->ann->id, 'paid_at' => '2026-10-05'], [
            ['type' => 'hourly', 'store_id' => $this->a->id, 'amount' => '100.00', 'issues' => [$this->ti1->id]],
            ['type' => 'gas', 'store_id' => $this->b->id, 'amount' => '20.00', 'issues' => [$this->ti3->id]],
        ]);
        $this->pay(['technician_id' => $this->bob->id, 'paid_at' => '2026-10-12'], [
            ['type' => 'fixed', 'store_id' => $this->a->id, 'amount' => '300.00', 'issues' => [$this->ti1->id, $this->ti2->id]],
        ]);
        $this->pay(['supplier_id' => $this->depot->id, 'paid_at' => '2026-10-20'], [
            ['type' => 'parts', 'storage_location_id' => $this->shelf->id, 'amount' => '50.00'],
        ]);
        // Left out: a mistaken payment, one in September (the previous
        // period), one in November.
        [, [$this->mistakenLine]] = $this->pay(['technician_id' => $this->ann->id, 'paid_at' => '2026-10-06', 'mistaken' => true], [
            ['type' => 'fixed', 'store_id' => $this->a->id, 'amount' => '999.00', 'issues' => [$this->ti1->id]],
        ]);
        $this->pay(['technician_id' => $this->ann->id, 'paid_at' => '2026-09-20'], [
            ['type' => 'other', 'store_id' => $this->a->id, 'amount' => '47.00'],
        ]);
        $this->pay(['technician_id' => $this->ann->id, 'paid_at' => '2026-11-02'], [
            ['type' => 'other', 'store_id' => $this->a->id, 'amount' => '1000.00'],
        ]);
    }

    private function issueAt(Store $store, Issue $issue): TicketIssue
    {
        $ticket = Ticket::factory()->for($store)->create();

        return TicketIssue::factory()->for($ticket)->create(['issue_id' => $issue->id, 'other_title' => null]);
    }

    /**
     * @return array{0: int, 1: list<int>}
     */
    private function pay(array $payment, array $lines): array
    {
        $paymentId = DB::table('payments')->insertGetId($payment + [
            'total' => collect($lines)->sum(fn ($l) => (float) $l['amount']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ids = [];
        foreach ($lines as $i => $line) {
            $issues = $line['issues'] ?? [];
            unset($line['issues']);
            $ids[] = $lineId = DB::table('payment_lines')->insertGetId($line + [
                'payment_id' => $paymentId, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($issues as $issueId) {
                DB::table('payment_line_ticket_issue')->insert(['payment_line_id' => $lineId, 'ticket_issue_id' => $issueId]);
            }
        }

        return [$paymentId, $ids];
    }

    private function url(array $query = []): string
    {
        return '/api/maintenance-analytics/finances?' . http_build_query(array_merge(self::OCTOBER, $query));
    }

    public function test_totals_breakdowns_and_trend(): void
    {
        $data = $this->getJson($this->url(), $this->headers())->assertOk()->json('data');

        $this->assertSame(['spent' => '470.00', 'previous_spent' => '47.00', 'change_pct' => 900, 'payments' => 3, 'lines' => 4], $data['totals']);

        $byType = collect($data['by_type'])->pluck('amount', 'type')->all();
        $this->assertSame(['hourly' => '100.00', 'fixed' => '300.00', 'inspection' => '0.00', 'parts' => '50.00', 'gas' => '20.00', 'other' => '0.00'], $byType);
        $this->assertSame('By the hour', $data['by_type'][0]['label']);

        $this->assertSame([
            ['store_number' => '03795-00001', 'amount' => '400.00'],
            ['store_number' => 'storage', 'amount' => '50.00'],
            ['store_number' => '03795-00002', 'amount' => '20.00'],
        ], $data['by_store']);

        $this->assertSame(['Bob' => '300.00', 'Ann' => '120.00'], collect($data['by_technician'])->pluck('amount', 'name')->all());
        $this->assertSame(['id' => $this->company->id, 'name' => 'Company'], $data['by_technician'][0]['category']);
        $this->assertSame([['id' => $this->depot->id, 'name' => 'Home Depot', 'amount' => '50.00']], $data['by_supplier']);
        $this->assertSame(['Company' => '300.00', 'In-house' => '120.00'], collect($data['by_category'])->pluck('amount', 'name')->all());

        // 31 days: by ISO week, every week present, Mondays as the key.
        $this->assertSame('week', $data['range']['trend_by']);
        $this->assertSame(
            ['2026-09-28' => '0.00', '2026-10-05' => '120.00', '2026-10-12' => '300.00', '2026-10-19' => '50.00', '2026-10-26' => '0.00'],
            collect($data['trend'])->pluck('amount', 'period')->all(),
        );
        $this->assertSame('100.00', $data['trend'][1]['by_type']['hourly']);
        $this->assertSame('20.00', $data['trend'][1]['by_type']['gas']);
    }

    public function test_a_long_range_trends_by_month(): void
    {
        $data = $this->getJson($this->url(['from' => '2026-01-01', 'to' => '2026-10-31']), $this->headers())->assertOk()->json('data');

        $this->assertSame('month', $data['range']['trend_by']);
        $trend = collect($data['trend'])->pluck('amount', 'period');
        $this->assertCount(10, $trend);
        $this->assertSame('47.00', $trend['2026-09']);
        $this->assertSame('470.00', $trend['2026-10']);
        $this->assertSame('0.00', $trend['2026-01']);
    }

    public function test_ticket_figures_share_a_line_between_its_issues(): void
    {
        IssueInspection::query()->create(['ticket_issue_id' => $this->ti1->id, 'status' => 'done', 'estimated_cost' => '500.00']);
        IssueInspection::query()->create(['ticket_issue_id' => $this->ti1->id, 'status' => 'done', 'estimated_cost' => '200.00']);
        IssueInspection::query()->create(['ticket_issue_id' => $this->ti1->id, 'status' => 'done', 'estimated_cost' => '999.00', 'mistaken' => true]);

        $base = ['amount' => '250.00', 'approver_name' => 'Pat', 'decided_at' => '2026-10-03 15:00:00'];
        IssueApproval::query()->create($base + ['ticket_issue_id' => $this->ti1->id, 'level' => 'specialist', 'decision' => 'approved']);
        IssueApproval::query()->create($base + ['ticket_issue_id' => $this->ti2->id, 'level' => 'manager', 'decision' => 'rejected']);
        IssueApproval::query()->create(['amount' => '600.00'] + $base + ['ticket_issue_id' => $this->ti3->id, 'level' => 'manager', 'decision' => 'approved']);
        IssueApproval::query()->create($base + ['ticket_issue_id' => $this->ti3->id, 'level' => 'manager', 'decision' => 'approved', 'mistaken' => true]);

        $tickets = $this->getJson($this->url(), $this->headers())->assertOk()->json('data.tickets');

        // ti1 100 + 150, ti2 150, ti3 20 → 420 over three tickets.
        $this->assertSame('140.00', $tickets['cost_per_ticket_avg']);
        $this->assertSame([$this->ti1->ticket_id, $this->ti2->ticket_id, $this->ti3->ticket_id], array_column($tickets['top_tickets'], 'ticket_id'));
        $this->assertSame(['ticket_id' => $this->ti1->ticket_id, 'store_number' => '03795-00001', 'title' => 'Oven', 'amount' => '250.00'], $tickets['top_tickets'][0]);
        $this->assertSame([
            ['issue_id' => $this->oven->id, 'title' => 'Oven', 'amount' => '400.00', 'tickets' => 2],
            ['issue_id' => $this->ti3->issue_id, 'title' => 'Fryer', 'amount' => '20.00', 'tickets' => 1],
        ], $tickets['by_issue']);
        $this->assertSame([['ticket_issue_id' => $this->ti1->id, 'title' => 'Oven', 'estimated' => '200.00', 'actual' => '250.00']], $tickets['estimate_vs_actual']);
        $this->assertSame([['store_number' => '03795-00001', 'issue_id' => $this->oven->id, 'title' => 'Oven', 'tickets' => 2, 'amount' => '400.00']], $tickets['recurring_costly']);
        $this->assertSame([
            ['level' => 'specialist', 'label' => 'Maintenance Specialist', 'count' => 1, 'amount' => '250.00'],
            ['level' => 'manager', 'label' => 'Maintenance Manager', 'count' => 1, 'amount' => '600.00'],
            ['level' => 'management', 'label' => 'Management', 'count' => 0, 'amount' => '0.00'],
        ], $tickets['approvals_by_level']);

        $atA = $this->getJson($this->url(['stores' => ['03795-00001']]), $this->headers())->json('data.tickets.approvals_by_level');
        $this->assertSame([1, 0, 0], array_column($atA, 'count'));
    }

    public function test_filters_cut_inside_payments(): void
    {
        $atB = $this->getJson($this->url(['stores' => ['03795-00002']]), $this->headers())->assertOk()->json('data');
        $this->assertSame('20.00', $atB['totals']['spent']);
        $this->assertSame([['store_number' => '03795-00002', 'amount' => '20.00']], $atB['by_store']);
        $this->assertSame('0.00', $atB['totals']['previous_spent']);
        $this->assertNull($atB['totals']['change_pct']);

        // A category is a technician's: supplier payments drop out.
        $company = $this->getJson($this->url(['category_id' => $this->company->id]), $this->headers())->json('data');
        $this->assertSame('300.00', $company['totals']['spent']);
        $this->assertSame([], $company['by_supplier']);

        $depot = $this->getJson($this->url(['supplier_id' => $this->depot->id]), $this->headers())->json('data');
        $this->assertSame('50.00', $depot['totals']['spent']);
        $this->assertSame([], $depot['by_technician']);

        $ann = $this->getJson($this->url(['technician_id' => $this->ann->id]), $this->headers())->json('data');
        $this->assertSame('120.00', $ann['totals']['spent']);
        $this->assertSame(2, $ann['totals']['lines']);
    }

    public function test_stock_is_valued_at_average_cost(): void
    {
        $part = Part::factory()->create();
        $stock = app(StockService::class);
        $line = fn (string $qty, int $dir, ?string $cost = null) => [
            'part_id' => $part->id, 'storage_location_id' => $this->shelf->id,
            'quantity' => $qty, 'direction' => $dir, 'unit_cost' => $cost,
        ];

        $stock->record(['moved_at' => '2026-09-01 10:00:00', 'type' => StockMovementType::OpeningStock], [$line('10', 1, '5.00')]);
        $stock->record(['moved_at' => '2026-10-20 10:00:00', 'type' => StockMovementType::Bought], [$line('10', 1, '7.00')]);
        $stock->record(['moved_at' => '2026-10-21 10:00:00', 'type' => StockMovementType::Used], [$line('4', -1)]);
        $stock->record(['moved_at' => '2026-10-22 10:00:00', 'type' => StockMovementType::StockCount], [$line('1', -1)]);
        $stock->record(['moved_at' => '2026-10-22 11:00:00', 'type' => StockMovementType::StockCount], [$line('2', 1)]);
        $stock->record(['moved_at' => '2026-10-23 10:00:00', 'type' => StockMovementType::DamagedLost], [$line('1', -1)]);
        // A purchase typed by mistake and undone: worth nothing either way.
        $wrong = $stock->record(['moved_at' => '2026-10-24 10:00:00', 'type' => StockMovementType::Bought, 'mistaken' => true], [$line('100', 1, '1.00')]);
        $stock->record(['moved_at' => '2026-10-24 11:00:00', 'type' => StockMovementType::Undone, 'reverses_stock_movement_id' => $wrong->id], [$line('100', -1, '1.00')]);

        $data = $this->getJson($this->url(), $this->headers())->assertOk()->json('data.stock');

        // Average cost (10×5 + 10×7) / 20 = 6; the count gain has no cost.
        // On hand 10 + 10 − 4 − 1 + 2 − 1 = 16 → 96.
        $this->assertSame([
            'on_hand_value' => '96.00',
            'bought_value' => '70.00',
            'used_value' => '24.00',
            'count_losses' => '6.00',
            'count_gains' => '12.00',
            'damaged_lost_value' => '6.00',
        ], $data);
    }

    public function test_unpaid_is_what_is_owed_now(): void
    {
        $trip = Trip::query()->create(['technician_id' => $this->ann->id, 'status' => 'closed', 'planned_date' => '2026-10-05']);
        $trip->stops()->create(['store_id' => $this->a->id, 'status' => 'done']);
        $session = fn (string $in, string $out) => TripSession::query()->create([
            'trip_id' => $trip->id, 'technician_id' => $this->ann->id, 'clock_in' => $in, 'clock_out' => $out,
        ]);

        // 4 h with a 30 min break: 210 min, unpaid.
        $open = $session('2026-10-05 08:00:00', '2026-10-05 12:00:00');
        TripEvent::query()->create(['trip_session_id' => $open->id, 'kind' => 'break_start', 'at' => '2026-10-05 10:00:00']);
        TripEvent::query()->create(['trip_session_id' => $open->id, 'kind' => 'break_end', 'at' => '2026-10-05 10:30:00']);
        // Paid by Ann's hourly line.
        $paid = $session('2026-10-06 08:00:00', '2026-10-06 09:00:00');
        DB::table('payment_line_trip_session')->insert(['payment_line_id' => $this->annLine, 'trip_session_id' => $paid->id, 'paid_minutes' => 60]);
        // Claimed only by a mistaken payment: still owed.
        $claimedByMistake = $session('2026-10-07 08:00:00', '2026-10-07 09:00:00');
        DB::table('payment_line_trip_session')->insert(['payment_line_id' => $this->mistakenLine, 'trip_session_id' => $claimedByMistake->id, 'paid_minutes' => 60]);
        // Mistaken session: nothing owed.
        TripSession::query()->create(['trip_id' => $trip->id, 'technician_id' => $this->ann->id, 'clock_in' => '2026-10-08 08:00:00', 'clock_out' => '2026-10-08 09:00:00', 'mistaken' => true]);

        $purchase = ['technician_id' => $this->ann->id, 'description' => 'Fuse', 'bought_at' => '2026-10-05 09:00:00', 'ticket_issue_id' => $this->ti1->id];
        TechnicianPurchase::query()->create($purchase + ['total_cost' => '35.50']);
        TechnicianPurchase::query()->create($purchase + ['total_cost' => '10.00', 'payment_line_id' => $this->annLine]);
        TechnicianPurchase::query()->create($purchase + ['total_cost' => '10.00', 'mistaken' => true]);

        $this->assertSame(
            ['sessions' => 2, 'paid_minutes' => 270, 'purchases' => 1, 'purchases_total' => '35.50'],
            $this->getJson($this->url(), $this->headers())->json('data.unpaid'),
        );
        $this->assertSame(
            ['sessions' => 0, 'paid_minutes' => 0, 'purchases' => 0, 'purchases_total' => '0.00'],
            $this->getJson($this->url(['category_id' => $this->company->id]), $this->headers())->json('data.unpaid'),
        );
        $this->assertSame(0, $this->getJson($this->url(['stores' => ['03795-00002']]), $this->headers())->json('data.unpaid.sessions'));
    }

    public function test_validation(): void
    {
        $this->getJson('/api/maintenance-analytics/finances', $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);

        // No cap on the range: over a year is answered like any other.
        $this->getJson($this->url(['from' => '2025-01-01', 'to' => '2026-02-10']), $this->headers())
            ->assertOk();

        $this->getJson($this->url(['category_id' => 99999]), $this->headers())
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id']);

        // One day is a range; stores are optional here.
        $this->getJson($this->url(['from' => '2026-10-05', 'to' => '2026-10-05']), $this->headers())
            ->assertOk()->assertJsonPath('data.totals.spent', '120.00');
    }
}
