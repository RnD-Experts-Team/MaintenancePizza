<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The stores a technician went to (or was planned to), one row per trip stop,
 * on the local day he arrived, else the day the trip was planned for. Stops at
 * a store written in words (`other_store`) have no store code and are not
 * here. Mistaken stops and stops of mistaken trips are left out. The ticket
 * issues of a stop are never joined, so a stop is one row.
 *
 * `minutes_on_site` is arrival to leaving, empty until both are set.
 */
class TripStopsDataset extends ReportDataset
{
    use LocalDates;

    public const KEY = 'maintenance.trip_stops';

    public function label(): string
    {
        return 'Trip stops';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Visit date', 'date', $this->dateColumn(), groupable: true),
            new Column('technician', 'Technician', 'string', 'technicians.name', groupable: true),
            new Column(
                'status',
                'Status',
                'enum',
                'trip_stops.status',
                options: [
                    ['value' => 'planned', 'label' => 'Planned'],
                    ['value' => 'arrived', 'label' => 'Arrived'],
                    ['value' => 'done', 'label' => 'Done'],
                    ['value' => 'not_done', 'label' => 'Not done'],
                ],
                groupable: true,
            ),
            new Column('stops', 'Stops', 'integer', 'trip_stops.id', measure: true, aggregations: ['count']),
            new Column('trips', 'Trips', 'integer', 'trips.id', measure: true, aggregations: ['count_distinct']),
            new Column(
                'minutes_on_site',
                'Minutes on site',
                'number',
                self::minutesBetween('trip_stops.arrived_at', 'trip_stops.left_at'),
                measure: true,
                aggregations: ['sum', 'avg', 'max'],
            ),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('trip_stops')
            ->join('trips', 'trips.id', '=', 'trip_stops.trip_id')
            ->join('technicians', 'technicians.id', '=', 'trips.technician_id')
            ->join('stores', 'stores.id', '=', 'trip_stops.store_id')
            ->where('trip_stops.mistaken', false)
            ->where('trips.mistaken', false);
    }

    public function storeColumn(): string
    {
        return 'trip_stops.store_id';
    }

    public function dateColumn(): string
    {
        return 'COALESCE('.self::localDate('trip_stops.arrived_at').', trips.planned_date)';
    }
}
