<?php

namespace App\Http\Controllers;

use App\Models\DailyPayEntry;
use App\Services\DailyPayEntryService;
use Illuminate\Http\JsonResponse;

/**
 * Re-pull the gathered hours and reimbursable parts for every payment on an
 * entry, from the attendance and part-usage records as they stand now.
 *
 * Lines whose hours were typed in are left exactly as typed.
 */
class DailyPayEntryRecalculationController extends Controller
{
    public function __construct(private DailyPayEntryService $service) {}

    public function __invoke(DailyPayEntry $dailyPayEntry): JsonResponse
    {
        return response()->json(['data' => $this->service->recalculate($dailyPayEntry)]);
    }
}
