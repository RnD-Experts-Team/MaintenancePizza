<?php

namespace App\Exports;

use App\Exports\Sheets\ApprovalsSheet;
use App\Exports\Sheets\AssignmentDelaysSheet;
use App\Exports\Sheets\AssignmentsSheet;
use App\Exports\Sheets\AttachmentsSheet;
use App\Exports\Sheets\CategoriesSheet;
use App\Exports\Sheets\InspectionsSheet;
use App\Exports\Sheets\IssuesSheet;
use App\Exports\Sheets\IssueStatusChangesSheet;
use App\Exports\Sheets\PartsSheet;
use App\Exports\Sheets\PaymentLinesSheet;
use App\Exports\Sheets\PaymentsSheet;
use App\Exports\Sheets\StockBalancesSheet;
use App\Exports\Sheets\StockCountsSheet;
use App\Exports\Sheets\StockMovementLinesSheet;
use App\Exports\Sheets\StockMovementsSheet;
use App\Exports\Sheets\StorageLocationsSheet;
use App\Exports\Sheets\StoresSheet;
use App\Exports\Sheets\SuppliersSheet;
use App\Exports\Sheets\TechnicianPurchasesSheet;
use App\Exports\Sheets\TechnicianTicketIssuesSheet;
use App\Exports\Sheets\TechniciansSheet;
use App\Exports\Sheets\TicketIssuesSheet;
use App\Exports\Sheets\TicketsSheet;
use App\Exports\Sheets\TripSessionsSheet;
use App\Exports\Sheets\TripsSheet;
use App\Exports\Sheets\WarrantiesSheet;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * One workbook with a sheet per entity — a full dump of every piece of data,
 * including soft-deleted reference records.
 *
 * The v1 sheets (daily pay, pay entries, attendance, part usages, diagnoses)
 * are gone with their features; their rows live on in the new sheets they
 * were converted into (payments, trips, technician purchases, inspections).
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
            new InspectionsSheet,
            new ApprovalsSheet,
            new WarrantiesSheet,
            new CategoriesSheet,
            new TechniciansSheet,
            new TechnicianTicketIssuesSheet,
            new TripsSheet,
            new TripSessionsSheet,
            new PaymentsSheet,
            new PaymentLinesSheet,
            new TechnicianPurchasesSheet,
            new SuppliersSheet,
            new IssuesSheet,
            new PartsSheet,
            new StorageLocationsSheet,
            new StockMovementsSheet,
            new StockMovementLinesSheet,
            new StockBalancesSheet,
            new StockCountsSheet,
            new AttachmentsSheet,
        ];
    }
}
