<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What is on hand now, one row per part and storage location. A snapshot: it
 * ignores the period, and its grain has no date. Parts and locations that were
 * deleted keep their rows. The shelf address (place levels) is not offered:
 * it is several values per row. There is no value column: the value of stock
 * is a FIFO walk over the ledger that only StockService can make.
 */
class StockBalancesDataset extends GlobalDataset
{
    public const KEY = 'maintenance.stock_balances';

    public function label(): string
    {
        return 'Stock on hand';
    }

    public function grain(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new Column('part', 'Part', 'string', 'parts.name', groupable: true),
            new Column('part_number', 'Part number', 'string', 'parts.part_number', groupable: true),
            new Column('brand', 'Brand', 'string', 'parts.brand', groupable: true),
            new Column('unit', 'Unit', 'string', 'parts.unit', groupable: true),
            new Column('location', 'Location', 'string', 'storage_locations.name', groupable: true),
            new Column(
                'state',
                'State',
                'enum',
                "CASE WHEN stock_balances.quantity < 0 THEN 'negative' WHEN stock_balances.quantity = 0 THEN 'none' ELSE 'in_stock' END",
                options: [
                    ['value' => 'negative', 'label' => 'Negative'],
                    ['value' => 'none', 'label' => 'None left'],
                    ['value' => 'in_stock', 'label' => 'In stock'],
                ],
                groupable: true,
            ),
            new Column('balances', 'Part-location pairs', 'integer', 'stock_balances.id', measure: true, aggregations: ['count']),
            new Column('parts', 'Parts', 'integer', 'stock_balances.part_id', measure: true, aggregations: ['count_distinct']),
            new Column('on_hand', 'On hand', 'number', 'stock_balances.quantity', measure: true, aggregations: ['sum', 'min', 'max']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('stock_balances')
            ->join('parts', 'parts.id', '=', 'stock_balances.part_id')
            ->join('storage_locations', 'storage_locations.id', '=', 'stock_balances.storage_location_id');
    }

    public function dateColumn(): ?string
    {
        return null;
    }
}
