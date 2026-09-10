<?php

namespace Tests\Feature;

use App\Enums\PartUsagePayer;
use App\Enums\PartUsageSource;
use App\Enums\StockMovementType;
use App\Exports\TicketsWorkbookExport;
use App\Models\Part;
use App\Models\StorageLocation;
use App\Models\Technician;
use App\Models\TicketIssue;
use App\Services\StockService;
use App\Services\WorkflowRecordService;
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

    public function test_every_sheet_maps_without_error_over_populated_data(): void
    {
        $part = Part::factory()->create();
        $shelf = StorageLocation::factory()->create();
        $technician = Technician::factory()->create();
        $issue = TicketIssue::factory()->create();
        $technician->ticketIssues()->attach($issue->id);

        app(StockService::class)->record([
            'moved_at' => now(),
            'type' => StockMovementType::Purchase,
            'storage_location_id' => $shelf->id,
        ], [[
            'part_id' => $part->id,
            'storage_location_id' => $shelf->id,
            'quantity' => '10',
            'direction' => 1,
            'unit_cost' => '5.00',
        ]]);

        $workflow = app(WorkflowRecordService::class);

        $workflow->createPartUsage([
            'ticket_issue_ids' => [$issue->id],
            'part_id' => $part->id,
            'quantity' => '4',
            'unit_cost' => '5.00',
            'source' => PartUsageSource::FromStorage->value,
            'paid_by' => PartUsagePayer::Technician->value,
            'paid_by_technician_id' => $technician->id,
            'storage_location_id' => $shelf->id,
            'returned_quantity' => '1',
            'returned_to_storage_location_id' => $shelf->id,
        ], []);

        $workflow->createAttendance([
            'technician_id' => $technician->id,
            'ticket_issue_ids' => [$issue->id],
            'start_clock' => '2026-09-10 08:00:00',
            'end_clock' => '2026-09-10 16:00:00',
            'start_travel' => '2026-09-10 07:00:00',
            'end_travel' => '2026-09-10 08:00:00',
        ], []);

        $sheets = (new TicketsWorkbookExport)->sheets();

        foreach ($sheets as $sheet) {
            $rows = $sheet->collection();
            $headings = $sheet->headings();

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
}
