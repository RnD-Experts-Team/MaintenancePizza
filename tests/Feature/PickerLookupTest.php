<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\IssueInspection;
use App\Models\Part;
use App\Models\PartUnit;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The lookups the dashboard's pickers call (docs/MAINTENANCE-V2.md §1 Parts,
 * §2 "Issue lookup", §4 "Inspection lookup"), over HTTP.
 */
class PickerLookupTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeAuthServer();
        $this->store = Store::factory()->create(['store_number' => '03795-00001']);
    }

    private function issue(string $title, array $attrs = [], ?Ticket $ticket = null): TicketIssue
    {
        return TicketIssue::factory()->create($attrs + [
            'ticket_id' => ($ticket ?? Ticket::factory()->for($this->store)->create())->id,
            'other_title' => $title,
            'description' => 'Nothing to see',
        ]);
    }

    // ------------------------------------------------------------------ Parts

    public function test_parts_search_matches_name_brand_model_and_part_number_ignoring_capitals(): void
    {
        Part::factory()->create(['name' => 'Fan motor', 'brand' => 'Hoshizaki', 'model' => 'FM-200', 'part_number' => 'HZ-4410']);
        Part::factory()->create(['name' => 'Door gasket', 'brand' => 'True', 'model' => 'G7', 'part_number' => 'TR-0091']);

        $names = fn (string $search) => collect($this->getJson('/api/parts?search='.urlencode($search), $this->headers())->assertOk()->json('data'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['Fan motor'], $names('FAN'));
        $this->assertSame(['Fan motor'], $names('hoshi'));
        $this->assertSame(['Door gasket'], $names('g7'));
        $this->assertSame(['Door gasket'], $names('tr-00'));
        $this->assertSame(['Door gasket', 'Fan motor'], $names(''));
        $this->assertSame([], $names('nothing like it'));
    }

    public function test_a_part_is_shown_like_its_list_row_with_its_units_count(): void
    {
        $part = Part::factory()->create(['name' => 'Compressor', 'brand' => 'Copeland', 'track_serials' => true]);
        PartUnit::query()->create(['part_id' => $part->id, 'serial_number' => 'SN-1', 'status' => 'in_stock']);
        PartUnit::query()->create(['part_id' => $part->id, 'serial_number' => 'SN-2', 'status' => 'installed']);

        $row = collect($this->getJson('/api/parts', $this->headers())->json('data'))->firstWhere('id', $part->id);
        $show = $this->getJson("/api/parts/{$part->id}", $this->headers())->assertOk()
            ->assertJsonPath('data.name', 'Compressor')
            ->assertJsonPath('data.brand', 'Copeland')
            ->assertJsonPath('data.track_serials', true)
            ->assertJsonPath('data.units_count', 2)
            ->json('data');

        unset($show['units_count']);
        $this->assertSame($row, $show);

        // A deleted part is still readable; an unknown one is not.
        $part->delete();
        $this->getJson("/api/parts/{$part->id}", $this->headers())->assertOk()->assertJsonPath('data.units_count', 2);
        $this->getJson('/api/parts/99999', $this->headers())->assertNotFound();
    }

    // ------------------------------------------------------------ Ticket issues

    public function test_ticket_issues_lookup_returns_light_rows_newest_first(): void
    {
        $older = $this->issue('Oven not heating', ['priority' => 'high']);
        $newer = $this->issue('Walk-in cooler', ['status' => 'scheduled']);
        $off = $this->issue('Sign light', [], Ticket::factory()->otherStore('Warehouse')->create());

        $rows = $this->getJson('/api/ticket-issues', $this->headers())->assertOk()->json('data');

        $this->assertSame([$off->id, $newer->id, $older->id], array_column($rows, 'id'));
        $this->assertSame([
            'id' => $older->id,
            'ticket_id' => $older->ticket_id,
            'title' => 'Oven not heating',
            'status' => 'new',
            'status_label' => 'New',
            'priority' => ['value' => 'high', 'label' => 'High'],
            'store' => ['id' => $this->store->id, 'store_number' => '03795-00001'],
            'other_store' => null,
        ], $rows[2]);
        $this->assertNull($rows[0]['store']);
        $this->assertSame('Warehouse', $rows[0]['other_store']);
    }

    public function test_ticket_issues_lookup_filters_by_search_store_status_and_limit(): void
    {
        $catalog = Issue::factory()->create(['title' => 'Fryer temperature']);
        $fryer = $this->issue('', ['issue_id' => $catalog->id, 'other_title' => null]);
        $cooler = $this->issue('Walk-in cooler', ['status' => 'scheduled', 'description' => 'Compressor rattles']);
        $elsewhere = Store::factory()->create(['store_number' => '03795-00002']);
        $there = $this->issue('Walk-in freezer', ['status' => 'closed'], Ticket::factory()->for($elsewhere)->create());

        $ids = fn (string $query) => array_column($this->getJson('/api/ticket-issues?'.$query, $this->headers())->assertOk()->json('data'), 'id');

        $this->assertSame([$fryer->id], $ids('search=FRYER'));
        $this->assertSame([$there->id, $cooler->id], $ids('search=walk-in'));
        $this->assertSame([$cooler->id], $ids('search=rattles'));
        $this->assertSame([$cooler->id], $ids('search=%23'.$cooler->ticket_id));
        $this->assertSame([$cooler->id], $ids('search='.$cooler->ticket_id));
        $this->assertSame([$there->id], $ids('store=03795-00002'));
        $this->assertSame([$there->id, $cooler->id], $ids('statuses[]=scheduled&statuses[]=closed'));
        $this->assertSame([$there->id], $ids('limit=1'));

        $this->getJson('/api/ticket-issues?limit=101', $this->headers())->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->getJson('/api/ticket-issues?statuses[]=deferred', $this->headers())->assertUnprocessable()->assertJsonValidationErrors('statuses.0');

        // A deleted ticket's issues are left out.
        $there->ticket->delete();
        $this->assertSame([$cooler->id, $fryer->id], $ids(''));
    }

    public function test_ticket_issues_lookup_defaults_to_twenty_rows(): void
    {
        foreach (range(1, 22) as $i) {
            $this->issue("Issue {$i}");
        }

        $this->getJson('/api/ticket-issues', $this->headers())->assertOk()->assertJsonCount(20, 'data');
        $this->getJson('/api/ticket-issues?limit=100', $this->headers())->assertOk()->assertJsonCount(22, 'data');
    }

    // ------------------------------------------------------------- Inspections

    public function test_inspections_lookup_says_whether_each_is_paid_and_filters_unpaid(): void
    {
        $tom = Technician::factory()->create(['name' => 'Tom']);
        $ann = Technician::factory()->create(['name' => 'Ann']);
        $issue = $this->issue('Oven not heating');

        $paid = IssueInspection::query()->create(['ticket_issue_id' => $issue->id, 'technician_id' => $tom->id, 'status' => 'done', 'scheduled_date' => '2026-10-01', 'done_at' => '2026-10-01 10:00:00', 'estimated_cost' => 120]);
        $unpaid = IssueInspection::query()->create(['ticket_issue_id' => $issue->id, 'technician_id' => $tom->id, 'status' => 'scheduled', 'scheduled_date' => '2026-10-08']);
        // Paid only by a payment that was a mistake: still unpaid.
        $wronglyPaid = IssueInspection::query()->create(['ticket_issue_id' => $issue->id, 'technician_id' => $tom->id, 'status' => 'done']);
        $annsOne = IssueInspection::query()->create(['ticket_issue_id' => $issue->id, 'technician_id' => $ann->id, 'status' => 'done']);
        IssueInspection::query()->create(['ticket_issue_id' => $issue->id, 'technician_id' => $tom->id, 'status' => 'done', 'mistaken' => true]);

        $payment = Payment::query()->create(['paid_at' => '2026-10-05', 'technician_id' => $tom->id, 'total' => 50]);
        PaymentLine::query()->create(['payment_id' => $payment->id, 'type' => 'inspection', 'store_id' => $this->store->id, 'amount' => 50, 'issue_inspection_id' => $paid->id]);
        $mistaken = Payment::query()->create(['paid_at' => '2026-10-05', 'technician_id' => $tom->id, 'total' => 50, 'mistaken' => true]);
        PaymentLine::query()->create(['payment_id' => $mistaken->id, 'type' => 'inspection', 'store_id' => $this->store->id, 'amount' => 50, 'issue_inspection_id' => $wronglyPaid->id]);

        $rows = collect($this->getJson("/api/issue-inspections?technician_id={$tom->id}", $this->headers())->assertOk()->json('data'));

        $this->assertSame([$wronglyPaid->id, $unpaid->id, $paid->id], $rows->pluck('id')->all());
        $this->assertSame([
            'id' => $paid->id,
            'ticket_issue' => [
                'id' => $issue->id,
                'ticket_id' => $issue->ticket_id,
                'title' => 'Oven not heating',
                'store' => ['id' => $this->store->id, 'store_number' => '03795-00001'],
                'other_store' => null,
            ],
            'technician' => ['id' => $tom->id, 'name' => 'Tom'],
            'status' => 'done',
            'status_label' => 'Done',
            'done_at' => $rows->last()['done_at'],
            'scheduled_date' => '2026-10-01',
            'estimated_cost' => '120.00',
            'paid' => true,
        ], $rows->last());
        $this->assertNotNull($rows->last()['done_at']);
        $this->assertFalse($rows->firstWhere('id', $wronglyPaid->id)['paid']);
        $this->assertNull($rows->firstWhere('id', $unpaid->id)['estimated_cost']);

        $ids = fn (string $query) => array_column($this->getJson('/api/issue-inspections?'.$query, $this->headers())->assertOk()->json('data'), 'id');
        $this->assertSame([$wronglyPaid->id, $unpaid->id], $ids("technician_id={$tom->id}&unpaid=1"));
        $this->assertSame([$annsOne->id, $wronglyPaid->id, $unpaid->id, $paid->id], $ids(''));
    }

    public function test_inspections_lookup_searches_the_issue_title_and_ticket_id(): void
    {
        $oven = $this->issue('Oven not heating');
        $cooler = $this->issue('Walk-in cooler');
        $a = IssueInspection::query()->create(['ticket_issue_id' => $oven->id, 'status' => 'done']);
        $b = IssueInspection::query()->create(['ticket_issue_id' => $cooler->id, 'status' => 'done']);

        $ids = fn (string $search) => array_column($this->getJson('/api/issue-inspections?search='.urlencode($search), $this->headers())->assertOk()->json('data'), 'id');

        $this->assertSame([$a->id], $ids('OVEN'));
        $this->assertSame([$b->id], $ids('#'.$cooler->ticket_id));
        $this->assertSame([], $ids('freezer'));

        $this->getJson('/api/issue-inspections?unpaid=maybe', $this->headers())->assertUnprocessable()->assertJsonValidationErrors('unpaid');
    }
}
