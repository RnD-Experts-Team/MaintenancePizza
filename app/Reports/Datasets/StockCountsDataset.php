<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One row per stock count sheet, on the local day it was started. The sheet's
 * lines are summed in one subquery per count and joined once, so the count's
 * own missing and extra values are never multiplied by its lines. `variance`
 * is counted minus expected over the lines somebody counted (negative =
 * missing). Missing and extra values are frozen when the count is applied and
 * are 0 on a draft.
 */
class StockCountsDataset extends GlobalDataset
{
    use LocalDates;

    public const KEY = 'maintenance.stock_counts';

    public function label(): string
    {
        return 'Stock counts';
    }

    public function grain(): array
    {
        return ['date'];
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Started date', 'date', self::localDate('stock_counts.started_at'), groupable: true),
            new Column('location', 'Location', 'string', 'storage_locations.name', groupable: true),
            new Column(
                'status',
                'Status',
                'enum',
                'stock_counts.status',
                options: [['value' => 'draft', 'label' => 'Draft'], ['value' => 'applied', 'label' => 'Applied']],
                groupable: true,
            ),
            new Column('counts', 'Counts', 'integer', 'stock_counts.id', measure: true, aggregations: ['count']),
            new Column('lines', 'Lines', 'integer', 'COALESCE(count_lines.line_count, 0)', measure: true, aggregations: ['sum', 'avg', 'max']),
            new Column('counted_lines', 'Counted lines', 'integer', 'COALESCE(count_lines.counted_lines, 0)', measure: true, aggregations: ['sum', 'avg', 'max']),
            new Column('variance', 'Variance (quantity)', 'number', 'COALESCE(count_lines.net_variance, 0)', measure: true, aggregations: ['sum', 'min', 'max']),
            new Column('missing_value', 'Missing value', 'money', 'stock_counts.missing_value', measure: true, aggregations: ['sum', 'avg', 'max']),
            new Column('extra_value', 'Extra value', 'money', 'stock_counts.extra_value', measure: true, aggregations: ['sum', 'avg', 'max']),
        ];
    }

    public function baseQuery(): Builder
    {
        $lines = DB::table('stock_count_lines')
            ->selectRaw('stock_count_id, COUNT(*) as line_count, '
                .'SUM(CASE WHEN counted IS NOT NULL THEN 1 ELSE 0 END) as counted_lines, '
                .'SUM(CASE WHEN counted IS NOT NULL THEN counted - expected ELSE 0 END) as net_variance')
            ->groupBy('stock_count_id');

        return DB::table('stock_counts')
            ->join('storage_locations', 'storage_locations.id', '=', 'stock_counts.storage_location_id')
            ->leftJoinSub($lines, 'count_lines', 'count_lines.stock_count_id', '=', 'stock_counts.id');
    }

    public function dateColumn(): string
    {
        return self::localDate('stock_counts.started_at');
    }
}
