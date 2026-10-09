<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaintenanceAnalyticsRequest;
use App\Services\MaintenanceAnalyticsService;

/**
 * GET /api/maintenance-analytics/{summary|activity|watchlist}
 *
 * One route, one pizzasys rule: the sections are three views of the same
 * question ("how are these stores' tickets doing?"), split so the page can
 * load them in parallel and page the long one.
 */
class MaintenanceAnalyticsController extends Controller
{
    public function __construct(private MaintenanceAnalyticsService $analytics)
    {
    }

    public function __invoke(MaintenanceAnalyticsRequest $request, string $section)
    {
        $stores = $this->analytics->stores((array) $request->input('stores', []));
        $recurringMin = $request->integer('recurring_min', MaintenanceAnalyticsService::RECURRING_MIN);
        $recurringDays = $request->integer('recurring_days', MaintenanceAnalyticsService::RECURRING_DAYS);
        $untouchedDays = $request->integer('untouched_days', MaintenanceAnalyticsService::UNTOUCHED_DAYS);

        return match ($section) {
            'summary' => ['data' => $this->analytics->summary(
                $stores, $request->from(), $request->to(), $recurringMin, $recurringDays, $untouchedDays,
            )],
            'activity' => $this->analytics->activity(
                $stores, $request->from(), $request->to(),
                max(1, $request->integer('page', 1)),
                min(100, max(1, $request->integer('per_page', 25))),
            ),
            'watchlist' => ['data' => $this->analytics->watchlist(
                $stores, $request->to(), $recurringMin, $recurringDays, $untouchedDays,
            )],
        };
    }
}
