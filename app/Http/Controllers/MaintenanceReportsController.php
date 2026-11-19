<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaintenanceReportsRequest;
use App\Services\MaintenanceReportsService;

/**
 * GET /api/maintenance-reports?start_date=&end_date=
 *
 * The weekly maintenance reports in one answer: weekly, pending, technical
 * issues, status and payments. Behind the X-Secret-Key header
 * (auth.secret.key), not a user's token: Excel (Power Query) reads it.
 */
class MaintenanceReportsController extends Controller
{
    public function __construct(private MaintenanceReportsService $reports)
    {
    }

    public function __invoke(MaintenanceReportsRequest $request)
    {
        return ['data' => $this->reports->report($request->startDate(), $request->endDate())];
    }
}
