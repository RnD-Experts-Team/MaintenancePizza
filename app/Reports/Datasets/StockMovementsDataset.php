<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Enums\StockMovementType;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock moving, one row per movement line, on the local day of the movement.
 * A movement reaches a store only when it was for a ticket issue (`used`);
 * the rest (bought, moved, counted...) have no store.
 *
 * The ledger nets to what is on hand only when a mistaken movement and the
 * `undone` movement that reverses it are both counted or both left out. They
 * are both left out here, so sums agree with the balances. The serial-unit
 * links of a line are never joined. `quantity` is unsigned; `net_quantity`
 * and `net_value` carry the direction (in +, out -). A line without a cost
 * has no value.
 */
class StockMovementsDataset extends ReportDataset
{
    use LocalDates;

    public const KEY = 'maintenance.stock_movements';

    public function label(): string
    {
        return 'Stock movements';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function heavy(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Moved date', 'date', self::localDate('stock_movements.moved_at'), groupable: true),
            new Column(
                'type',
                'Type',
                'enum',
                'stock_movements.type',
                options: array_map(fn (StockMovementType $t) => ['value' => $t->value, 'label' => $t->label()], StockMovementType::cases()),
                groupable: true,
            ),
            new Column(
                'direction',
                'Direction',
                'enum',
                "CASE WHEN stock_movement_lines.direction > 0 THEN 'in' ELSE 'out' END",
                options: [['value' => 'in', 'label' => 'Into storage'], ['value' => 'out', 'label' => 'Out of storage']],
                groupable: true,
            ),
            new Column('part', 'Part', 'string', 'parts.name', groupable: true),
            new Column('location', 'Location', 'string', 'storage_locations.name', groupable: true),
            new Column('lines', 'Lines', 'integer', 'stock_movement_lines.id', measure: true, aggregations: ['count']),
            new Column('movements', 'Movements', 'integer', 'stock_movements.id', measure: true, aggregations: ['count_distinct']),
            new Column('quantity', 'Quantity', 'number', 'stock_movement_lines.quantity', measure: true, aggregations: ['sum', 'avg', 'max']),
            new Column('net_quantity', 'Net quantity', 'number', 'stock_movement_lines.quantity * stock_movement_lines.direction', measure: true, aggregations: ['sum']),
            new Column('value', 'Value', 'money', 'stock_movement_lines.total_cost', measure: true, aggregations: ['sum', 'avg', 'max']),
            new Column('net_value', 'Net value', 'money', 'stock_movement_lines.total_cost * stock_movement_lines.direction', measure: true, aggregations: ['sum']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('stock_movement_lines')
            ->join('stock_movements', 'stock_movements.id', '=', 'stock_movement_lines.stock_movement_id')
            ->join('parts', 'parts.id', '=', 'stock_movement_lines.part_id')
            ->join('storage_locations', 'storage_locations.id', '=', 'stock_movement_lines.storage_location_id')
            ->leftJoin('ticket_issues', 'ticket_issues.id', '=', 'stock_movements.ticket_issue_id')
            ->leftJoin('tickets', 'tickets.id', '=', 'ticket_issues.ticket_id')
            ->leftJoin('stores', 'stores.id', '=', 'tickets.store_id')
            ->where('stock_movements.mistaken', false)
            ->whereNull('stock_movements.reverses_stock_movement_id');
    }

    public function storeColumn(): string
    {
        return 'tickets.store_id';
    }

    public function dateColumn(): string
    {
        return self::localDate('stock_movements.moved_at');
    }
}
