<?php

declare(strict_types=1);

namespace App\Reports;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\AssignmentsDataset;
use App\Reports\Datasets\PaymentLinesDataset;
use App\Reports\Datasets\PaymentsDataset;
use App\Reports\Datasets\StockBalancesDataset;
use App\Reports\Datasets\StockCountsDataset;
use App\Reports\Datasets\StockMovementsDataset;
use App\Reports\Datasets\TechnicianPurchasesDataset;
use App\Reports\Datasets\TicketIssuesDataset;
use App\Reports\Datasets\TicketsDataset;
use App\Reports\Datasets\TripsDataset;
use App\Reports\Datasets\TripStopsDataset;

/**
 * The datasets this service offers the report builder. routes/api.php gives
 * each one a literal query route; each needs its own pizzasys auth rule.
 */
final class ReportDatasets
{
    /** @var list<class-string<ReportDataset>> */
    public const DATASETS = [
        TicketIssuesDataset::class,
        AssignmentsDataset::class,
        TicketsDataset::class,
        PaymentLinesDataset::class,
        PaymentsDataset::class,
        TechnicianPurchasesDataset::class,
        StockMovementsDataset::class,
        StockBalancesDataset::class,
        StockCountsDataset::class,
        TripsDataset::class,
        TripStopsDataset::class,
    ];

    public static function make(string $key): ReportDataset
    {
        foreach (self::DATASETS as $dataset) {
            if ($dataset::KEY === $key) {
                return new $dataset;
            }
        }

        abort(404, 'Unknown dataset.');
    }
}
