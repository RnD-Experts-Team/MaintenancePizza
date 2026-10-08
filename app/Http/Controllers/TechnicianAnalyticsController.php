<?php

namespace App\Http\Controllers;

use App\Http\Requests\TechnicianAnalyticsRequest;
use App\Models\Technician;
use App\Services\TechnicianAnalyticsService;

/**
 * The Technicians page.
 *
 *   GET /technician-analytics                    every technician: paid, visits, hours
 *   GET /technicians/{technician}/analytics      one technician in detail
 */
class TechnicianAnalyticsController extends Controller
{
    public function __construct(private TechnicianAnalyticsService $analytics)
    {
    }

    public function index(TechnicianAnalyticsRequest $request)
    {
        return ['data' => $this->analytics->overview($request->filters($this->analytics))];
    }

    public function show(TechnicianAnalyticsRequest $request, Technician $technician)
    {
        return ['data' => $this->analytics->technician($technician, $request->filters($this->analytics))];
    }
}
