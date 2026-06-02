<?php

namespace App\Http\Controllers;

use App\Exports\TicketsWorkbookExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    /**
     * Download a multi-sheet workbook containing every piece of data.
     */
    public function __invoke(): BinaryFileResponse
    {
        return Excel::download(new TicketsWorkbookExport, 'maintenancepizza-export.xlsx');
    }
}
