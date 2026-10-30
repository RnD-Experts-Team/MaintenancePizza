<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaintenanceAnalyticsRequest;
use App\Services\CoverageAnalyticsService;
use App\Services\FinanceAnalyticsService;
use App\Services\MaintenanceAnalyticsService;

/**
 * GET /api/maintenance-analytics/{summary|activity|watchlist|finances|coverage}
 *
 * One route, one pizzasys rule: the sections are views of the same page,
 * split so it can load them in parallel and page the long one.
 *
 *   summary / activity / watchlist  how these stores' tickets are doing
 *   finances                        where the money went (docs §6)
 *   coverage                        who covers which store and issue (docs §6)
 */
class MaintenanceAnalyticsController extends Controller
{
    public function __construct(
        private MaintenanceAnalyticsService $analytics,
        private FinanceAnalyticsService $finances,
        private CoverageAnalyticsService $coverage,
    ) {
    }

    public function __invoke(MaintenanceAnalyticsRequest $request, string $section)
    {
        $stores = $this->analytics->stores((array) $request->input('stores', []));

        if ($section === 'finances') {
            return ['data' => $this->finances->report(
                $request->fromDate(),
                $request->toDate(),
                $request->filled('stores') ? $stores->pluck('id')->map(fn ($id) => (int) $id)->all() : null,
                $request->filled('category_id') ? $request->integer('category_id') : null,
                $request->filled('technician_id') ? $request->integer('technician_id') : null,
                $request->filled('supplier_id') ? $request->integer('supplier_id') : null,
            )];
        }

        if ($section === 'coverage') {
            return ['data' => $this->coverage->report(
                $request->filled('stores') ? $stores : null,
                $request->filled('issue_ids') ? $request->ids('issue_ids') : null,
                $request->filled('category_id') ? $request->integer('category_id') : null,
            )];
        }

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
