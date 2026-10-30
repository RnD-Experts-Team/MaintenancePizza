<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Exports\TicketsWorkbookExport;
use App\Models\Assignment;
use App\Models\IssueApproval;
use App\Models\IssueInspection;
use App\Models\Part;
use App\Models\Payment;
use App\Models\StockCount;
use App\Models\StorageLocation;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\TechnicianPurchase;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripEvent;
use App\Models\TripSession;
use App\Models\Warranty;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * The workbook has a sheet per entity and every sheet maps its own columns, so
 * a renamed field breaks the export silently. This walks all of them over real
 * rows.
 */
class ExportWorkbookTest extends TestCase
{
    use RefreshDatabase;

    private const V2_SHEETS = [
        'Payments', 'Payment Lines', 'Trips', 'Trip Sessions', 'Technician Purchases', 'Suppliers',
        'Inspections', 'Approvals', 'Stock Counts',
    ];

    private function populate(): void
    {
        $part = Part::factory()->create();
        $shelf = StorageLocation::factory()->create();
        $technician = Technician::factory()->create();
        $issue = TicketIssue::factory()->create();
        $technician->ticketIssues()->attach($issue->id);

        app(StockService::class)->record([
            'moved_at' => now(),
            'type' => StockMovementType::Bought,
            'storage_location_id' => $shelf->id,
        ], [[
            'part_id' => $part->id,
            'storage_location_id' => $shelf->id,
            'quantity' => '10',
            'direction' => 1,
            'unit_cost' => '5.00',
        ]]);

        // A booking with a technician, and a warranty, on the issue.
        $assignment = Assignment::factory()->create(['technician_id' => $technician->id]);
        $assignment->ticketIssues()->attach($issue->id);
        $assignment->delays()->create(['old_date' => '2026-09-10', 'new_date' => '2026-09-11', 'reason' => 'Rain']);
        Warranty::factory()->create()->ticketIssues()->attach($issue->id);

        // v2: an inspection and its approval, a trip with a stop and a paid
        // session, a payment to the technician and one to a supplier, a
        // technician purchase, and a stock count.
        $inspection = IssueInspection::query()->create([
            'ticket_issue_id' => $issue->id, 'technician_id' => $technician->id, 'status' => 'done',
            'scheduled_date' => '2026-10-01', 'findings' => 'Worn belt', 'estimated_cost' => '120.00',
        ]);
        IssueApproval::query()->create([
            'ticket_issue_id' => $issue->id, 'issue_inspection_id' => $inspection->id, 'amount' => '120.00',
            'level' => 'specialist', 'decision' => 'approved', 'approver_name' => 'Pat', 'decided_at' => now(),
        ]);

        $trip = Trip::query()->create(['technician_id' => $technician->id, 'status' => 'closed', 'planned_date' => '2026-10-02']);
        $stop = $trip->stops()->create(['store_id' => $issue->ticket->store_id, 'status' => 'done']);
        $stop->ticketIssues()->attach($issue->id, ['purpose' => 'work', 'task_status' => 'done']);
        $session = TripSession::query()->create([
            'trip_id' => $trip->id, 'technician_id' => $technician->id,
            'clock_in' => '2026-10-02 08:00:00', 'clock_out' => '2026-10-02 12:00:00', 'source' => 'specialist',
        ]);
        TripEvent::query()->create(['trip_session_id' => $session->id, 'kind' => 'break_start', 'at' => '2026-10-02 10:00:00']);
        TripEvent::query()->create(['trip_session_id' => $session->id, 'kind' => 'break_end', 'at' => '2026-10-02 10:30:00']);

        $payment = Payment::query()->create(['paid_at' => '2026-10-03', 'technician_id' => $technician->id, 'method' => 'cash', 'total' => '105.00']);
        $line = $payment->lines()->create([
            'type' => 'hourly', 'store_id' => $issue->ticket->store_id, 'amount' => '105.00', 'hours' => '3.50', 'rate' => '30',
        ]);
        $line->ticketIssues()->attach($issue->id);
        $line->tripSessions()->attach($session->id, ['paid_minutes' => 210]);

        $supplier = Supplier::query()->create(['name' => 'Home Depot', 'website' => 'https://homedepot.example']);
        $supplier->payments()->create(['paid_at' => '2026-10-03', 'total' => '50.00'])->lines()->create([
            'type' => 'parts', 'storage_location_id' => $shelf->id, 'amount' => '50.00', 'part_id' => $part->id,
            'quantity' => '10', 'unit_cost' => '5', 'bought_from_supplier_id' => $supplier->id,
        ]);

        TechnicianPurchase::query()->create([
            'technician_id' => $technician->id, 'trip_id' => $trip->id, 'ticket_issue_id' => $issue->id, 'part_id' => $part->id,
            'description' => 'Belt', 'total_cost' => '12.00', 'supplier_id' => $supplier->id, 'bought_at' => now(),
        ]);

        $count = StockCount::query()->create(['storage_location_id' => $shelf->id, 'status' => 'draft', 'started_at' => now()]);
        $count->lines()->create(['part_id' => $part->id, 'expected' => '10', 'counted' => '9']);
    }

    public function test_every_sheet_maps_without_error_over_populated_data(): void
    {
        $this->populate();

        $sheets = (new TicketsWorkbookExport)->sheets();
        $titles = array_map(fn ($s) => $s->title(), $sheets);

        foreach (self::V2_SHEETS as $title) {
            $this->assertContains($title, $titles);
        }
        $this->assertSame(count($titles), count(array_unique($titles)), 'Two sheets share a title.');

        foreach ($sheets as $sheet) {
            $rows = $sheet->collection();
            $headings = $sheet->headings();

            if (in_array($sheet->title(), self::V2_SHEETS, true)) {
                $this->assertNotEmpty($rows, $sheet->title() . ' exported no rows.');
            }

            foreach ($rows as $row) {
                $mapped = $sheet->map($row);

                $this->assertSameSize(
                    $headings,
                    $mapped,
                    $sheet->title() . ' maps a different number of columns than it declares headings.'
                );
            }
        }

        // And the real writer runs over the same data.
        Excel::store(new TicketsWorkbookExport, 'test-export.xlsx', 'local');
        $this->assertTrue(true);
    }

    public function test_the_session_sheet_carries_its_paid_minutes_and_payment(): void
    {
        $this->populate();

        $sheet = collect((new TicketsWorkbookExport)->sheets())->first(fn ($s) => $s->title() === 'Trip Sessions');
        $row = array_combine($sheet->headings(), $sheet->map($sheet->collection()->first()));

        $this->assertSame(210, $row['Paid Minutes']);
        $this->assertSame(30, $row['Break Minutes']);
        $this->assertSame('yes', $row['Paid']);
        $this->assertSame(Payment::query()->whereNotNull('technician_id')->value('id'), $row['Payment ID']);
    }

    public function test_the_export_route_sits_behind_the_secret_key(): void
    {
        config(['services.x_secret_key' => 'shh']);

        $this->get('/api/export/excel')->assertUnauthorized();
        $this->get('/api/export/excel', ['X-Secret-Key' => 'shh'])->assertOk()->assertDownload('maintenancepizza-export.xlsx');
    }
}
