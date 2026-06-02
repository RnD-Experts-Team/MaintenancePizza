<?php

namespace App\Exports;

use App\Exports\Sheets\AssignmentDelaysSheet;
use App\Exports\Sheets\AssignmentsSheet;
use App\Exports\Sheets\AttachmentsSheet;
use App\Exports\Sheets\AttendanceEntriesSheet;
use App\Exports\Sheets\CategoriesSheet;
use App\Exports\Sheets\DiagnosesSheet;
use App\Exports\Sheets\IssuesSheet;
use App\Exports\Sheets\IssueStatusChangesSheet;
use App\Exports\Sheets\PartsSheet;
use App\Exports\Sheets\PartUsagesSheet;
use App\Exports\Sheets\PayEntriesSheet;
use App\Exports\Sheets\StoresSheet;
use App\Exports\Sheets\TechniciansSheet;
use App\Exports\Sheets\TicketIssuesSheet;
use App\Exports\Sheets\TicketsSheet;
use App\Exports\Sheets\WarrantiesSheet;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * One workbook with a sheet per entity — a full dump of every piece of data,
 * including soft-deleted reference records.
 */
class TicketsWorkbookExport implements WithMultipleSheets
{
    use Exportable;

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new StoresSheet,
            new TicketsSheet,
            new TicketIssuesSheet,
            new IssueStatusChangesSheet,
            new AssignmentsSheet,
            new AssignmentDelaysSheet,
            new DiagnosesSheet,
            new AttendanceEntriesSheet,
            new PartUsagesSheet,
            new PayEntriesSheet,
            new WarrantiesSheet,
            new CategoriesSheet,
            new TechniciansSheet,
            new IssuesSheet,
            new PartsSheet,
            new AttachmentsSheet,
        ];
    }
}
