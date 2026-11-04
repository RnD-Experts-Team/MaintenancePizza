<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Parts a technician bought with his own money, one row per purchase, on the
 * local day he bought them. A purchase reaches a store through the ticket
 * issue it was bought for; one bought for the shelf has no store. Mistaken
 * purchases are left out. The part is the catalog part's name, else what the
 * technician wrote he bought.
 */
class TechnicianPurchasesDataset extends ReportDataset
{
    use LocalDates;

    public const KEY = 'maintenance.technician_purchases';

    public function label(): string
    {
        return 'Technician purchases';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Bought date', 'date', self::localDate('technician_purchases.bought_at'), groupable: true),
            new Column('technician', 'Technician', 'string', 'technicians.name', groupable: true),
            new Column('part', 'Part', 'string', 'COALESCE(parts.name, technician_purchases.description)', groupable: true),
            new Column('supplier', 'Supplier', 'string', 'suppliers.name', groupable: true),
            new Column(
                'payback',
                'Paid back',
                'enum',
                "CASE WHEN technician_purchases.payment_line_id IS NULL THEN 'waiting' ELSE 'paid_back' END",
                options: [['value' => 'waiting', 'label' => 'Waiting to be paid back'], ['value' => 'paid_back', 'label' => 'Paid back']],
                groupable: true,
            ),
            new Column('purchases', 'Purchases', 'integer', 'technician_purchases.id', measure: true, aggregations: ['count']),
            new Column('quantity', 'Quantity', 'number', 'technician_purchases.quantity', measure: true, aggregations: ['sum', 'avg', 'max']),
            new Column('total_cost', 'Total cost', 'money', 'technician_purchases.total_cost', measure: true, aggregations: ['sum', 'avg', 'min', 'max']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('technician_purchases')
            ->join('technicians', 'technicians.id', '=', 'technician_purchases.technician_id')
            ->leftJoin('ticket_issues', 'ticket_issues.id', '=', 'technician_purchases.ticket_issue_id')
            ->leftJoin('tickets', 'tickets.id', '=', 'ticket_issues.ticket_id')
            ->leftJoin('stores', 'stores.id', '=', 'tickets.store_id')
            ->leftJoin('parts', 'parts.id', '=', 'technician_purchases.part_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'technician_purchases.supplier_id')
            ->where('technician_purchases.mistaken', false);
    }

    public function storeColumn(): string
    {
        return 'tickets.store_id';
    }

    public function dateColumn(): string
    {
        return self::localDate('technician_purchases.bought_at');
    }
}
